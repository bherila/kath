/**
 * Client-side derivative generation for media uploads.
 *
 * Thumbnails (and video poster frames) are produced in the browser so the app
 * server never decodes images. Downscaling uses a stepped OffscreenCanvas
 * (recursive halving with high-quality smoothing), which avoids the aliasing a
 * single-pass drawImage produces for large reductions — no third-party resize
 * library required. Photos additionally get a display-size JPEG and a
 * perceptual (blockhash) hash for near-duplicate detection.
 */

import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex } from '@noble/hashes/utils.js';
import { bmvbhash } from 'blockhash-core';

/** Longest-edge bound for the generated thumbnail/poster JPEG. */
const THUMBNAIL_MAX_EDGE = 480;
const THUMBNAIL_QUALITY = 0.78;

/** Longest-edge bound for the full-screen display JPEG (gallery lightbox). */
const DISPLAY_MAX_EDGE = 2048;
const DISPLAY_QUALITY = 0.85;

/** Blocks per side for the perceptual hash: 16×16 = 256 bits = 32 bytes. */
const PHASH_BITS = 16;

/**
 * Cap the canvas used for hashing. blockhash is scale-invariant, so bounding to
 * 256px avoids allocating a width×height×4 RGBA buffer for large originals.
 */
const PHASH_MAX_EDGE = 256;

export interface PhotoDerivatives {
  /** JPEG thumbnail. */
  thumbnail: Blob;
  /** Phone-screen-sized JPEG, so viewers never download the original. */
  display: Blob;
  /**
   * Base64-encoded 32-byte blockhash of each of the eight rotation/mirror
   * orientations; index 0 is the image as displayed.
   */
  perceptualHashes: string[];
  /** Decoded pixel size (after the camera's orientation is applied). */
  width: number;
  height: number;
}

/**
 * Files up to this size are hashed by Web Crypto in one read: fast, but it
 * holds the whole file in memory (its digest can't stream). Larger files are
 * hashed incrementally in HASH_CHUNK_BYTES slices, so a multi-gigabyte video
 * never has to fit in a phone's memory.
 */
const NATIVE_HASH_MAX_BYTES = 64 * 1024 * 1024;
const HASH_CHUNK_BYTES = 8 * 1024 * 1024;

/**
 * Compute a SHA-256 of the file's bytes as a lowercase hex string. It rejects
 * byte-identical re-uploads before any bytes are sent, and identifies a large
 * video's resumable upload session. Returns null if hashing fails; callers
 * then upload without the exact-duplicate check.
 */
export async function computeFileHash(file: File, onProgress?: (fraction: number) => void): Promise<string | null> {
  try {
    if (file.size <= NATIVE_HASH_MAX_BYTES && typeof crypto !== 'undefined' && crypto.subtle !== undefined) {
      const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
      onProgress?.(1);
      return bytesToHex(new Uint8Array(digest));
    }

    const hasher = sha256.create();
    for (let offset = 0; offset < file.size; offset += HASH_CHUNK_BYTES) {
      const chunk = new Uint8Array(await file.slice(offset, offset + HASH_CHUNK_BYTES).arrayBuffer());
      hasher.update(chunk);
      onProgress?.(Math.min(1, (offset + chunk.byteLength) / file.size));
      // Yield between chunks so hashing a long video doesn't freeze the page.
      await new Promise((resolve) => setTimeout(resolve, 0));
    }
    return bytesToHex(hasher.digest());
  } catch {
    return null;
  }
}

/**
 * Whether the browser supports the APIs this module needs. Callers should treat
 * derivative generation as best-effort and upload without a thumbnail when this
 * is false (older Safari lacks OffscreenCanvas.convertToBlob).
 */
export function supportsClientDerivatives(): boolean {
  return (
    typeof createImageBitmap === 'function' &&
    typeof OffscreenCanvas === 'function' &&
    typeof new OffscreenCanvas(1, 1).convertToBlob === 'function'
  );
}

/**
 * Generate a thumbnail, a display-size JPEG and a perceptual hash for an image
 * file. All are derived from a single decode of the source bitmap.
 */
export async function generatePhotoDerivatives(file: File): Promise<PhotoDerivatives> {
  const bitmap = await createImageBitmap(file);
  try {
    const [thumbnail, display, perceptualHashes] = await Promise.all([
      resizeToMaxEdge(bitmap, THUMBNAIL_MAX_EDGE, THUMBNAIL_QUALITY),
      resizeToMaxEdge(bitmap, DISPLAY_MAX_EDGE, DISPLAY_QUALITY),
      computePerceptualHashes(bitmap),
    ]);
    return { thumbnail, display, perceptualHashes, width: bitmap.width, height: bitmap.height };
  } finally {
    bitmap.close();
  }
}

/**
 * Capture a poster frame from a video file as a JPEG thumbnail. Seeks a little
 * past the start to skip black leader frames. Resolves null if the video can't
 * be decoded/drawn (e.g. an unsupported codec), so the caller falls back to a
 * posterless upload.
 */
export async function generateVideoPoster(file: File): Promise<Blob | null> {
  const url = URL.createObjectURL(file);
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;
  video.preload = 'auto';
  video.src = url;

  try {
    await waitForEvent(video, 'loadedmetadata');
    // A short offset avoids an all-black first frame; clamp to the duration.
    const target = Number.isFinite(video.duration) ? Math.min(0.5, video.duration / 2) : 0;
    video.currentTime = target;
    await waitForEvent(video, 'seeked');

    const { videoWidth: w, videoHeight: h } = video;
    if (w === 0 || h === 0) {
      return null;
    }

    const [targetWidth, targetHeight] = fitWithin(w, h, THUMBNAIL_MAX_EDGE);
    const canvas = new OffscreenCanvas(targetWidth, targetHeight);
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return null;
    }
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, 0, 0, targetWidth, targetHeight);

    return await canvas.convertToBlob({ type: 'image/jpeg', quality: THUMBNAIL_QUALITY });
  } catch {
    return null;
  } finally {
    video.removeAttribute('src');
    video.load();
    URL.revokeObjectURL(url);
  }
}

/**
 * Compute the 256-bit blockhash of the image in each of the eight dihedral
 * orientations (four 90° rotations × a mirror), base64-encoded, index 0 being
 * the image as displayed. The server compares two photos at their
 * best-matching orientation, so a rotated or mirrored copy still matches;
 * blockhash itself is scale-invariant, so a resized copy does too. (Arbitrary
 * rotations and large crops still need feature matching.)
 *
 * The bitmap is downscaled once to PHASH_MAX_EDGE to cap memory, and each
 * orientation is drawn from that small copy.
 */
export async function computePerceptualHashes(bitmap: ImageBitmap): Promise<string[]> {
  const [w, h] = fitWithin(bitmap.width, bitmap.height, PHASH_MAX_EDGE);
  const small = new OffscreenCanvas(w, h);
  const ctx = small.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get 2d context from OffscreenCanvas');
  }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bitmap, 0, 0, w, h);

  const hashes: string[] = [];
  for (let orientation = 0; orientation < 8; orientation++) {
    hashes.push(hexToBase64(blockhashForOrientation(small, w, h, orientation)));
  }
  return hashes;
}

/**
 * Blockhash (64-char hex) of one dihedral orientation of the bitmap: rotation =
 * orientation % 4 (×90°), with a horizontal mirror when orientation >= 4. The
 * source is drawn into a canvas sized for the rotation (width/height swap on the
 * 90°/270° cases) so nothing is clipped.
 */
function blockhashForOrientation(source: OffscreenCanvas, w: number, h: number, orientation: number): string {
  const rotation = orientation % 4;
  const mirror = orientation >= 4;
  const swap = rotation === 1 || rotation === 3;
  const cw = swap ? h : w;
  const ch = swap ? w : h;

  const canvas = new OffscreenCanvas(cw, ch);
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get 2d context from OffscreenCanvas');
  }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';

  // Canvas transforms post-multiply, so the mirror (set first) is applied last —
  // i.e. the source is rotated, then mirrored — which keeps the draw inside the
  // canvas bounds for every orientation.
  if (mirror) {
    ctx.translate(cw, 0);
    ctx.scale(-1, 1);
  }
  if (rotation === 1) {
    ctx.translate(cw, 0);
    ctx.rotate(Math.PI / 2);
  } else if (rotation === 2) {
    ctx.translate(cw, ch);
    ctx.rotate(Math.PI);
  } else if (rotation === 3) {
    ctx.translate(0, ch);
    ctx.rotate(-Math.PI / 2);
  }
  ctx.drawImage(source, 0, 0, w, h);

  // bmvbhash returns a 64-char hex string (256 bits for bits=16).
  return bmvbhash(ctx.getImageData(0, 0, cw, ch), PHASH_BITS);
}

function hexToBase64(hex: string): string {
  const bytes = new Uint8Array(32);
  for (let i = 0; i < 32; i++) {
    bytes[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
  }
  return btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join(''));
}

/**
 * Compute target dimensions that fit `width`×`height` within `maxEdge` on the
 * longest side, preserving aspect ratio and never upscaling.
 */
function fitWithin(width: number, height: number, maxEdge: number): [number, number] {
  if (width <= maxEdge && height <= maxEdge) {
    return [width, height];
  }
  if (width >= height) {
    return [maxEdge, Math.max(1, Math.round((height / width) * maxEdge))];
  }
  return [Math.max(1, Math.round((width / height) * maxEdge)), maxEdge];
}

/**
 * Resize an ImageBitmap to fit within `maxEdge`, downscaling in steps of at most
 * 50% per pass for quality, then encode as JPEG.
 */
async function resizeToMaxEdge(source: ImageBitmap, maxEdge: number, quality: number): Promise<Blob> {
  const [targetWidth, targetHeight] = fitWithin(source.width, source.height, maxEdge);

  let currentWidth = source.width;
  let currentHeight = source.height;
  let current: ImageBitmap | OffscreenCanvas = source;

  while (currentWidth > targetWidth * 2 || currentHeight > targetHeight * 2) {
    const nextWidth = Math.max(targetWidth, Math.floor(currentWidth / 2));
    const nextHeight = Math.max(targetHeight, Math.floor(currentHeight / 2));
    current = drawTo(current, nextWidth, nextHeight);
    currentWidth = nextWidth;
    currentHeight = nextHeight;
  }

  const finalCanvas = drawTo(current, targetWidth, targetHeight);
  return await finalCanvas.convertToBlob({ type: 'image/jpeg', quality });
}

function drawTo(source: ImageBitmap | OffscreenCanvas, width: number, height: number): OffscreenCanvas {
  const canvas = new OffscreenCanvas(width, height);
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Could not get 2d context from OffscreenCanvas');
  }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(source, 0, 0, width, height);
  return canvas;
}

function waitForEvent(target: HTMLMediaElement, event: string): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const onDone = (): void => {
      cleanup();
      resolve();
    };
    const onError = (): void => {
      cleanup();
      reject(new Error(`Video failed to ${event}.`));
    };
    const cleanup = (): void => {
      target.removeEventListener(event, onDone);
      target.removeEventListener('error', onError);
    };
    target.addEventListener(event, onDone, { once: true });
    target.addEventListener('error', onError, { once: true });
  });
}
