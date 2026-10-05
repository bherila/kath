/**
 * JSON calls to the wedding hub's session-gated endpoints. Errors carry the
 * HTTP status so callers can tell "already shared" (409) from failures.
 */
export class ApiError extends Error {
  readonly status: number;

  constructor(status: number, message: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function requestJson<T>(method: 'GET' | 'POST' | 'DELETE', url: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': csrfToken(),
  };
  const init: RequestInit = { method, headers, credentials: 'same-origin' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const response = await fetch(url, init);
  const text = await response.text();
  let data: unknown = null;
  if (text !== '') {
    try {
      data = JSON.parse(text);
    } catch {
      data = null;
    }
  }

  if (!response.ok) {
    const message = typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string'
      ? data.message
      : response.statusText || 'Request failed.';
    throw new ApiError(response.status, message);
  }

  return data as T;
}

export interface SignedUpload {
  url: string;
  headers: Record<string, string>;
}

export interface StoreUploadResponse {
  ulid: string;
  multipart: boolean;
  upload_url: string;
  upload_headers: Record<string, string>;
  display_upload: SignedUpload | null;
  thumbnail_upload: SignedUpload | null;
}

export interface MultipartInitResponse {
  upload_id: string;
  part_size_bytes: number;
  max_part_number: number;
}

export interface SignedPartResponse {
  parts: { part_number: number; url: string; headers: Record<string, string> }[];
}

export interface GalleryItem {
  ulid: string;
  kind: 'photo' | 'video';
  guest_name: string | null;
  mine: boolean;
  created_at: string;
  thumb_url: string | null;
  display_url: string | null;
  original_url: string;
  /** Videos: the playback proxy URL (it 404s until transcoding finishes). */
  master_url: string | null;
  /** Videos not yet known to be transcoded, as of the last check. */
  processing: boolean;
}

export interface GalleryPage {
  items: GalleryItem[];
  next_cursor: string | null;
}
