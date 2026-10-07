/**
 * When a photo or video was captured, read from the file's own metadata, so
 * the gallery can run in the order things happened rather than the order
 * guests got around to uploading them.
 *
 * - Photos: EXIF DateTimeOriginal (or CreateDate), with OffsetTimeOriginal
 *   when the camera wrote one. Without an offset the time is taken as the
 *   uploader's local time — guests' phones are on the event's clock.
 * - Videos: QuickTime/MP4 metadata. Apple's com.apple.quicktime.creationdate
 *   (local time with offset, and kept when Photos exports a copy) wins over
 *   the movie header's creation time (UTC).
 *
 * Everything is best-effort: any failure means "unknown", and the server
 * falls back to the upload time.
 */

import type { FileKind } from '@/wedding/uploader';

/** Seconds between the QuickTime epoch (1904-01-01) and the Unix epoch. */
const QUICKTIME_EPOCH_OFFSET = 2_082_844_800;

/** moov is metadata and sample tables: a few MB even for long videos. */
const MAX_MOOV_BYTES = 64 * 1024 * 1024;
const MAX_TOP_LEVEL_BOXES = 1_000;

const APPLE_CREATION_DATE_KEY = 'com.apple.quicktime.creationdate';

/** Capture time as an ISO 8601 UTC instant, or null when unknown. */
export async function readCaptureTime(file: File, kind: FileKind): Promise<string | null> {
  try {
    const date = kind === 'photo' ? await readPhotoCaptureTime(file) : await readVideoCaptureTime(file);
    return date !== null && isPlausible(date) ? date.toISOString() : null;
  } catch {
    return null;
  }
}

/** Clocks reset to 1970/2000 and far-future dates are not capture times. */
function isPlausible(date: Date): boolean {
  const time = date.getTime();
  return Number.isFinite(time) && date.getUTCFullYear() >= 2000 && time <= Date.now() + 24 * 60 * 60 * 1000;
}

/** EXIF tags read, by id (exifr's lite build may not translate names). */
const EXIF_TAGS = {
  DateTimeOriginal: 0x9003,
  CreateDate: 0x9004,
  OffsetTime: 0x9010,
  OffsetTimeOriginal: 0x9011,
} as const;

async function readPhotoCaptureTime(file: File): Promise<Date | null> {
  const { default: exifr } = await import('exifr/dist/lite.esm.mjs');
  const tags = (await exifr.parse(file, {
    // The capture date lives in the Exif IFD (reached through IFD0, which
    // can't be skipped); skip every other block.
    exif: true,
    gps: false,
    interop: false,
    ifd1: false,
    translateKeys: false,
    reviveValues: false,
  })) as Record<number, unknown> | undefined;
  if (tags === undefined) {
    return null;
  }

  const text = (tag: number): string | null => (typeof tags[tag] === 'string' ? tags[tag] : null);
  const original = text(EXIF_TAGS.DateTimeOriginal);
  const raw = original ?? text(EXIF_TAGS.CreateDate);
  const offset = original !== null ? text(EXIF_TAGS.OffsetTimeOriginal) ?? text(EXIF_TAGS.OffsetTime) : text(EXIF_TAGS.OffsetTime);
  return raw === null ? null : parseExifDate(raw, offset);
}

/**
 * EXIF "YYYY:MM:DD HH:MM:SS" with an optional "+HH:MM" offset. Without an
 * offset the time is read as this browser's local time.
 */
export function parseExifDate(value: string, offset: string | null): Date | null {
  const match = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(value.trim());
  if (match === null) {
    return null;
  }
  const [, year, month, day, hour, minute, second] = match;
  const zone = offset !== null && /^[+-]\d{2}:\d{2}$/.test(offset.trim()) ? offset.trim() : null;
  if (zone !== null) {
    return new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}${zone}`);
  }
  return new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
}

async function readVideoCaptureTime(file: File): Promise<Date | null> {
  const moov = await readTopLevelBox(file, 'moov');
  return moov === null ? null : parseMoovCaptureTime(moov);
}

/** Find a top-level box by walking box headers (moov can sit after mdat). */
async function readTopLevelBox(file: File, wanted: string): Promise<DataView | null> {
  let offset = 0;
  for (let count = 0; count < MAX_TOP_LEVEL_BOXES && offset + 8 <= file.size; count += 1) {
    const header = new DataView(await file.slice(offset, offset + 16).arrayBuffer());
    let size = header.getUint32(0);
    const type = fourcc(header, 4);
    let headerSize = 8;
    if (size === 1) {
      if (header.byteLength < 16) {
        return null;
      }
      size = Number(header.getBigUint64(8));
      headerSize = 16;
    } else if (size === 0) {
      size = file.size - offset;
    }
    if (size < headerSize) {
      return null;
    }
    if (type === wanted) {
      if (size - headerSize > MAX_MOOV_BYTES) {
        return null;
      }
      return new DataView(await file.slice(offset + headerSize, offset + size).arrayBuffer());
    }
    offset += size;
  }
  return null;
}

/** Capture time from a moov box's payload. */
export function parseMoovCaptureTime(moov: DataView): Date | null {
  let headerTime: Date | null = null;
  let appleTime: Date | null = null;

  for (const box of children(moov, 0, moov.byteLength)) {
    if (box.type === 'mvhd') {
      headerTime = parseMvhd(moov, box.start, box.end);
    } else if (box.type === 'meta') {
      appleTime = parseAppleCreationDate(moov, box.start, box.end) ?? appleTime;
    } else if (box.type === 'udta') {
      for (const inner of children(moov, box.start, box.end)) {
        if (inner.type === 'meta') {
          appleTime = parseAppleCreationDate(moov, inner.start, inner.end) ?? appleTime;
        }
      }
    }
  }
  return appleTime ?? headerTime;
}

function parseMvhd(view: DataView, start: number, end: number): Date | null {
  const version = view.getUint8(start);
  const seconds = version === 1
    ? (start + 12 <= end ? Number(view.getBigUint64(start + 4)) : 0)
    : (start + 8 <= end ? view.getUint32(start + 4) : 0);
  // Zero means "not set" (common on screen recordings and edits).
  return seconds > QUICKTIME_EPOCH_OFFSET ? new Date((seconds - QUICKTIME_EPOCH_OFFSET) * 1000) : null;
}

/**
 * QuickTime metadata (meta → keys + ilst): find the value stored under
 * com.apple.quicktime.creationdate, e.g. "2026-09-27T17:04:12-0700".
 */
function parseAppleCreationDate(view: DataView, start: number, end: number): Date | null {
  // An ISO BMFF meta box has a version/flags word before its children; a
  // QuickTime one doesn't. The first child is always hdlr.
  const contentStart = start + 8 <= end && fourcc(view, start + 4) === 'hdlr' ? start : start + 4;

  let keyIndex: number | null = null;
  let ilst: Box | null = null;
  for (const box of children(view, contentStart, end)) {
    if (box.type === 'keys') {
      keyIndex = findKeyIndex(view, box.start, box.end, APPLE_CREATION_DATE_KEY);
    } else if (box.type === 'ilst') {
      ilst = box;
    }
  }
  if (keyIndex === null || ilst === null) {
    return null;
  }

  for (const item of children(view, ilst.start, ilst.end, true)) {
    if (item.index !== keyIndex) {
      continue;
    }
    for (const data of children(view, item.start, item.end)) {
      // data: type indicator (1 = UTF-8), locale, then the value.
      if (data.type === 'data' && data.start + 8 <= data.end && view.getUint32(data.start) === 1) {
        return parseIsoWithOffset(ascii(view, data.start + 8, data.end));
      }
    }
  }
  return null;
}

/** keys: version/flags, entry count, then (size, namespace, name) entries. 1-based. */
function findKeyIndex(view: DataView, start: number, end: number, wanted: string): number | null {
  if (start + 8 > end) {
    return null;
  }
  const count = view.getUint32(start + 4);
  let offset = start + 8;
  for (let index = 1; index <= count && offset + 8 <= end; index += 1) {
    const size = view.getUint32(offset);
    if (size < 8 || offset + size > end) {
      return null;
    }
    if (ascii(view, offset + 8, offset + size) === wanted) {
      return index;
    }
    offset += size;
  }
  return null;
}

/** "2026-09-27T17:04:12-0700" (Apple writes the offset without a colon). */
function parseIsoWithOffset(text: string): Date | null {
  const match = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$/.exec(text.trim());
  if (match === null) {
    return null;
  }
  const [, local, zone] = match;
  const normalized = zone === 'Z' || zone === undefined ? 'Z' : `${zone.slice(0, 3)}:${zone.slice(-2)}`;
  const date = new Date(`${local}${normalized}`);
  return Number.isNaN(date.getTime()) ? null : date;
}

interface Box {
  type: string;
  /** For ilst items: the type field read as a 1-based key index. */
  index: number;
  /** Payload range (after the 8-byte header). */
  start: number;
  end: number;
}

function* children(view: DataView, start: number, end: number, indexed = false): Generator<Box> {
  let offset = start;
  while (offset + 8 <= end) {
    const size = view.getUint32(offset);
    if (size < 8 || offset + size > end) {
      return;
    }
    yield {
      type: fourcc(view, offset + 4),
      index: indexed ? view.getUint32(offset + 4) : 0,
      start: offset + 8,
      end: offset + size,
    };
    offset += size;
  }
}

/** Metadata keys and dates are ASCII; decoding them needs no TextDecoder. */
function ascii(view: DataView, start: number, end: number): string {
  let text = '';
  for (let offset = start; offset < end; offset += 1) {
    text += String.fromCharCode(view.getUint8(offset));
  }
  return text;
}

function fourcc(view: DataView, offset: number): string {
  return String.fromCharCode(view.getUint8(offset), view.getUint8(offset + 1), view.getUint8(offset + 2), view.getUint8(offset + 3));
}
