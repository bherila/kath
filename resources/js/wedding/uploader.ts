import { ApiError, type MultipartInitResponse, requestJson, type SignedPartResponse, type StoreUploadResponse } from '@/wedding/api';
import { readCaptureTime } from '@/wedding/captureTime';
import {
  computeFileHash,
  generatePhotoDerivatives,
  generateVideoPoster,
  supportsClientDerivatives,
} from '@/wedding/imageProcessing';
import {
  type CompletedMultipartPart,
  type MultipartPartToSign,
  putToSignedUrl,
  readMultipartSession,
  removeMultipartSession,
  saveMultipartSession,
  uploadMultipartFile,
  withRetries,
} from '@/wedding/upload';

export type FileKind = 'photo' | 'video';

export interface UploadLimits {
  photo_bytes: number;
  video_bytes: number;
  photo_types: string[];
  video_types: string[];
}

/** Finished outcome of one file. */
export type UploadOutcome = 'uploaded' | 'duplicate';

const EXTENSION_TYPES: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  gif: 'image/gif',
  heic: 'image/heic',
  heif: 'image/heif',
  avif: 'image/avif',
  mp4: 'video/mp4',
  m4v: 'video/x-m4v',
  mov: 'video/quicktime',
  webm: 'video/webm',
  '3gp': 'video/3gpp',
};

/**
 * Some browsers hand over HEIC/HEIF (and the odd video) with an empty
 * `File.type`; fall back to the extension.
 */
export function contentTypeFor(file: File): string {
  if (file.type !== '') {
    return file.type.toLowerCase();
  }
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  return EXTENSION_TYPES[ext] ?? '';
}

export function kindFor(file: File, limits: UploadLimits): FileKind | null {
  const type = contentTypeFor(file);
  if (limits.photo_types.includes(type)) {
    return 'photo';
  }
  if (limits.video_types.includes(type)) {
    return 'video';
  }
  return null;
}

/**
 * Which of these hashes are already in the shared gallery. A failed check is
 * not fatal: the upload endpoint still rejects exact duplicates.
 */
export async function findExistingHashes(hashes: string[]): Promise<Set<string>> {
  if (hashes.length === 0) {
    return new Set();
  }
  try {
    const response = await requestJson<{ existing: string[] }>('POST', '/wedding/api/uploads/check', { hashes });
    return new Set(response.existing);
  } catch {
    return new Set();
  }
}

export { computeFileHash };

interface Derivatives {
  display: Blob | null;
  thumbnail: Blob | null;
  perceptualHashes: string[] | null;
  width: number | null;
  height: number | null;
}

async function buildDerivatives(file: File, kind: FileKind): Promise<Derivatives> {
  const none: Derivatives = { display: null, thumbnail: null, perceptualHashes: null, width: null, height: null };
  if (!supportsClientDerivatives()) {
    return none;
  }
  try {
    if (kind === 'photo') {
      return await generatePhotoDerivatives(file);
    }
    return { ...none, thumbnail: await generateVideoPoster(file) };
  } catch {
    // e.g. a HEIC this browser can't decode: share the original without previews.
    return none;
  }
}

/**
 * localStorage key for a large video's multipart session. Only a content hash
 * may identify a resumable session: name/size/mtime can match a different
 * video, and resuming it would splice two files into one object. Every file is
 * hashed (large ones in chunks), so this only falls back to a one-off key —
 * never looked up again, always a fresh session — if hashing failed.
 */
function multipartSessionKey(fileHash: string | null): { key: string; resumable: boolean } {
  if (fileHash !== null) {
    return { key: `wedding-multipart:${fileHash}`, resumable: true };
  }
  return { key: `wedding-multipart:unverified:${crypto.randomUUID()}`, resumable: false };
}

async function putDerivative(target: { url: string; headers: Record<string, string> } | null, blob: Blob | null, signal: AbortSignal): Promise<void> {
  if (target === null || blob === null) {
    return;
  }
  // Best-effort: the server drops a missing derivative on completion.
  await putToSignedUrl(target.url, blob, target.headers, () => {}, { signal }).catch(() => {});
}

/**
 * Upload one file: derivatives, presign (409 = already shared), PUT or
 * multipart to R2, then confirm. `fileHash` is computed by the caller so the
 * batch pre-check can skip known duplicates before any of this runs.
 */
export async function uploadFile(
  file: File,
  kind: FileKind,
  fileHash: string | null,
  onProgress: (fraction: number) => void,
  signal: AbortSignal,
): Promise<UploadOutcome> {
  const contentType = contentTypeFor(file);
  const { key: sessionKey, resumable: canResume } = multipartSessionKey(fileHash);
  const resumable = kind === 'video' && canResume ? readMultipartSession(sessionKey) : null;

  if (resumable !== null) {
    try {
      await runMultipart(file, sessionKey, resumable.uploadUlid, onProgress, signal, resumable);
      return 'uploaded';
    } catch (err) {
      // The server no longer knows this session (expired or superseded):
      // forget it and start over rather than failing on every retry.
      if (!(err instanceof ApiError) || (err.status !== 404 && err.status !== 422)) {
        throw err;
      }
      removeMultipartSession(sessionKey);
    }
  }

  const [derivatives, capturedAt] = await Promise.all([buildDerivatives(file, kind), readCaptureTime(file, kind)]);

  let created: StoreUploadResponse;
  try {
    created = await requestJson<StoreUploadResponse>('POST', '/wedding/api/uploads', {
      filename: file.name,
      content_type: contentType,
      size: file.size,
      file_hash: fileHash,
      perceptual_hashes: derivatives.perceptualHashes,
      width: derivatives.width,
      height: derivatives.height,
      captured_at: capturedAt,
      display_size: derivatives.display?.size ?? null,
      thumbnail_size: derivatives.thumbnail?.size ?? null,
    });
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      return 'duplicate';
    }
    throw err;
  }

  await putDerivative(created.display_upload, derivatives.display, signal);
  await putDerivative(created.thumbnail_upload, derivatives.thumbnail, signal);

  if (created.multipart) {
    await runMultipart(file, sessionKey, created.ulid, onProgress, signal, null);
    return 'uploaded';
  }

  // Transient failures (a dropped connection, a network switch) are retried
  // with backoff; completing is idempotent on the server.
  await withRetries(() => putToSignedUrl(created.upload_url, file, created.upload_headers, onProgress, { signal }), signal);
  await withRetries(() => requestJson('POST', `/wedding/api/uploads/${created.ulid}/complete`, {}), signal);
  return 'uploaded';
}

async function runMultipart(
  file: File,
  sessionKey: string,
  ulid: string,
  onProgress: (fraction: number) => void,
  signal: AbortSignal,
  resumed: ReturnType<typeof readMultipartSession>,
): Promise<void> {
  let session = resumed;
  if (session === null) {
    const init = await requestJson<MultipartInitResponse>('POST', `/wedding/api/uploads/${ulid}/multipart`, {});
    session = {
      uploadUlid: ulid,
      uploadId: init.upload_id,
      partSizeBytes: init.part_size_bytes,
      completedParts: [],
      createdAt: new Date().toISOString(),
    };
    saveMultipartSession(sessionKey, session);
  }

  const { uploadId } = session;
  await uploadMultipartFile(file, {
    sessionKey,
    session,
    signal,
    onProgress,
    presignParts: async (parts: MultipartPartToSign[]) => {
      const response = await requestJson<SignedPartResponse>('POST', `/wedding/api/uploads/${ulid}/multipart/parts`, {
        upload_id: uploadId,
        part_numbers: parts.map((part) => part.partNumber),
        part_sizes: Object.fromEntries(parts.map((part) => [part.partNumber, part.sizeBytes])),
      });
      return response.parts;
    },
    complete: async (parts: CompletedMultipartPart[]) => {
      await requestJson('POST', `/wedding/api/uploads/${ulid}/multipart/complete`, { upload_id: uploadId, parts });
    },
    abort: async () => {
      await requestJson('POST', `/wedding/api/uploads/${ulid}/multipart/abort`, { upload_id: uploadId });
    },
  });
}

