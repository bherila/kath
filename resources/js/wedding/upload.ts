/**
 * PUT a file to a presigned URL using XHR so we can report progress (the fetch
 * API does not expose upload progress). The headers must be exactly those the
 * URL was signed with (returned by the API alongside the URL), otherwise R2
 * rejects the signature.
 */
interface PutToSignedUrlOptions {
  signal?: AbortSignal;
}

interface SignedUploadResult {
  etag: string | null;
}

interface SignedPart {
  part_number: number;
  url: string;
  headers: Record<string, string>;
}

export interface MultipartPartToSign {
  partNumber: number;
  sizeBytes: number;
}

export interface CompletedMultipartPart {
  part_number: number;
  etag: string;
}

export interface MultipartUploadSession {
  uploadUlid: string;
  uploadId: string;
  partSizeBytes: number;
  completedParts: CompletedMultipartPart[];
  createdAt: string;
}

interface UploadMultipartFileOptions {
  sessionKey: string;
  session: MultipartUploadSession;
  presignParts: (parts: MultipartPartToSign[]) => Promise<SignedPart[]>;
  complete: (parts: CompletedMultipartPart[]) => Promise<void>;
  abort: () => Promise<void>;
  onProgress: (fraction: number) => void;
  signal?: AbortSignal;
}

const MAX_PART_RETRIES = 3;

export function putToSignedUrl(
  url: string,
  file: Blob,
  headers: Record<string, string>,
  onProgress: (fraction: number) => void,
  options: PutToSignedUrlOptions = {},
): Promise<void> {
  return putBlobToSignedUrl(url, file, headers, onProgress, options).then(() => {});
}

export function saveMultipartSession(key: string, session: MultipartUploadSession): void {
  window.localStorage.setItem(key, JSON.stringify(session));
}

export function readMultipartSession(key: string): MultipartUploadSession | null {
  const raw = window.localStorage.getItem(key);
  if (!raw) {
    return null;
  }

  try {
    const parsed = JSON.parse(raw) as Partial<MultipartUploadSession>;
    if (
      typeof parsed.uploadUlid === 'string'
      && typeof parsed.uploadId === 'string'
      && typeof parsed.partSizeBytes === 'number'
      && Array.isArray(parsed.completedParts)
    ) {
      return {
        uploadUlid: parsed.uploadUlid,
        uploadId: parsed.uploadId,
        partSizeBytes: parsed.partSizeBytes,
        completedParts: parsed.completedParts.filter(isCompletedPart),
        createdAt: typeof parsed.createdAt === 'string' ? parsed.createdAt : new Date().toISOString(),
      };
    }
  } catch {
    /* ignore corrupt resume state */
  }

  window.localStorage.removeItem(key);
  return null;
}

export function removeMultipartSession(key: string): void {
  window.localStorage.removeItem(key);
}

export async function uploadMultipartFile(file: File, options: UploadMultipartFileOptions): Promise<void> {
  const totalParts = Math.ceil(file.size / options.session.partSizeBytes);
  const completed = new Map<number, CompletedMultipartPart>(
    options.session.completedParts.map((part) => [part.part_number, part]),
  );

  const persist = (): void => {
    saveMultipartSession(options.sessionKey, {
      ...options.session,
      completedParts: Array.from(completed.values()).sort((a, b) => a.part_number - b.part_number),
    });
  };

  try {
    for (let partNumber = 1; partNumber <= totalParts; partNumber += 1) {
      if (completed.has(partNumber)) {
        reportMultipartProgress(file, options.session.partSizeBytes, completed.size, 0, options.onProgress);
        continue;
      }

      const start = (partNumber - 1) * options.session.partSizeBytes;
      const end = Math.min(start + options.session.partSizeBytes, file.size);
      const blob = file.slice(start, end);
      const signedPart = await withRetries(async () => {
        const [part] = await options.presignParts([{ partNumber, sizeBytes: blob.size }]);
        if (!part) {
          throw new Error('Upload part could not be signed.');
        }

        return part;
      }, options.signal);

      const result = await withRetries(
        () => putBlobToSignedUrl(
          signedPart.url,
          blob,
          signedPart.headers,
          (fraction) => reportMultipartProgress(file, options.session.partSizeBytes, completed.size, blob.size * fraction, options.onProgress),
          putOptions(options.signal),
        ),
        options.signal,
      );

      if (!result.etag) {
        throw new Error('Upload part completed without an ETag. Check the bucket CORS expose headers.');
      }

      completed.set(partNumber, { part_number: partNumber, etag: result.etag });
      persist();
      reportMultipartProgress(file, options.session.partSizeBytes, completed.size, 0, options.onProgress);
    }

    await options.complete(Array.from(completed.values()).sort((a, b) => a.part_number - b.part_number));
    removeMultipartSession(options.sessionKey);
    options.onProgress(1);
  } catch (err) {
    if (isAbortError(err)) {
      await options.abort().catch(() => {});
      removeMultipartSession(options.sessionKey);
    }

    throw err;
  }
}

function putBlobToSignedUrl(
  url: string,
  file: Blob,
  headers: Record<string, string>,
  onProgress: (fraction: number) => void,
  options: PutToSignedUrlOptions = {},
): Promise<SignedUploadResult> {
  return new Promise<SignedUploadResult>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url, true);
    for (const [name, value] of Object.entries(headers)) {
      if (name.toLowerCase() === 'content-length') {
        continue;
      }

      xhr.setRequestHeader(name, value);
    }

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total);
      }
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve({ etag: xhr.getResponseHeader('ETag') });
      } else {
        reject(new Error(`Upload failed (HTTP ${xhr.status}).`));
      }
    };

    const abort = (): void => {
      xhr.abort();
      reject(new DOMException('Upload canceled.', 'AbortError'));
    };

    if (options.signal?.aborted) {
      abort();
      return;
    }

    options.signal?.addEventListener('abort', abort, { once: true });
    xhr.onerror = () => reject(new Error('Upload failed: network error.'));
    xhr.onabort = () => reject(new DOMException('Upload canceled.', 'AbortError'));
    xhr.onloadend = () => options.signal?.removeEventListener('abort', abort);
    xhr.send(file);
  });
}

function isCompletedPart(value: unknown): value is CompletedMultipartPart {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const candidate = value as Partial<CompletedMultipartPart>;
  return typeof candidate.part_number === 'number' && typeof candidate.etag === 'string';
}

function putOptions(signal: AbortSignal | undefined): PutToSignedUrlOptions {
  return signal ? { signal } : {};
}

function reportMultipartProgress(
  file: File,
  partSizeBytes: number,
  completedPartCount: number,
  activePartLoadedBytes: number,
  onProgress: (fraction: number) => void,
): void {
  const completedBytes = Math.min(completedPartCount * partSizeBytes, file.size);
  onProgress(Math.min(1, (completedBytes + activePartLoadedBytes) / file.size));
}

async function withRetries<T>(operation: () => Promise<T>, signal?: AbortSignal): Promise<T> {
  let lastError: unknown;
  for (let attempt = 1; attempt <= MAX_PART_RETRIES; attempt += 1) {
    if (signal?.aborted) {
      throw new DOMException('Upload canceled.', 'AbortError');
    }

    try {
      return await operation();
    } catch (err) {
      if (isAbortError(err)) {
        throw err;
      }

      lastError = err;
      if (attempt < MAX_PART_RETRIES) {
        await delay(500 * attempt, signal);
      }
    }
  }

  throw lastError instanceof Error ? lastError : new Error('Upload failed.');
}

function delay(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timeoutId = window.setTimeout(resolve, ms);
    const abort = (): void => {
      window.clearTimeout(timeoutId);
      reject(new DOMException('Upload canceled.', 'AbortError'));
    };

    if (signal?.aborted) {
      abort();
      return;
    }

    signal?.addEventListener('abort', abort, { once: true });
  });
}

function isAbortError(err: unknown): boolean {
  return err instanceof DOMException && err.name === 'AbortError';
}
