import { parseExifDate, readCaptureTime } from '@/wedding/captureTime';

const ascii = (text: string): number[] => Array.from(text, (c) => c.charCodeAt(0));
const u16 = (n: number): number[] => [(n >> 8) & 0xff, n & 0xff];
const u32 = (n: number): number[] => [(n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff];

/** A minimal JPEG whose EXIF holds DateTimeOriginal (+ offset, if given). */
function jpegWithExif(dateTime: string, offset: string | null): File {
  return jpegWithTags(offset === null ? { 0x9003: dateTime } : { 0x9003: dateTime, 0x9011: offset });
}

/** A minimal JPEG whose Exif IFD holds these ASCII tags. */
function jpegWithTags(tags: Record<number, string>): File {
  const entries = Object.entries(tags)
    .map(([tag, value]) => ({ tag: Number(tag), value: `${value}\0` }))
    .sort((a, b) => a.tag - b.tag);
  // TIFF (big-endian): header, IFD0 with one entry (Exif IFD pointer), Exif IFD, values.
  const ifd0At = 8;
  const exifIfdAt = ifd0At + 2 + 12 + 4;
  let valueAt = exifIfdAt + 2 + 12 * entries.length + 4;
  const exifEntries: number[] = [];
  const values: number[] = [];
  for (const entry of entries) {
    exifEntries.push(...u16(entry.tag), ...u16(2), ...u32(entry.value.length), ...u32(valueAt));
    values.push(...ascii(entry.value));
    valueAt += entry.value.length;
  }
  const tiff = [
    ...ascii('MM'), ...u16(42), ...u32(ifd0At),
    ...u16(1), ...u16(0x8769), ...u16(4), ...u32(1), ...u32(exifIfdAt), ...u32(0),
    ...u16(entries.length), ...exifEntries, ...u32(0),
    ...values,
  ];
  const app1 = [...ascii('Exif'), 0, 0, ...tiff];
  const bytes = [0xff, 0xd8, 0xff, 0xe1, ...u16(app1.length + 2), ...app1, 0xff, 0xd9];
  return new File([new Uint8Array(bytes)], 'IMG.JPG', { type: 'image/jpeg' });
}

function box(type: string, ...payload: number[][]): number[] {
  const body = payload.flat();
  return [...u32(body.length + 8), ...ascii(type), ...body];
}

/** A minimal MOV: ftyp, mdat, then moov (as cameras write it, after the media). */
function movie(createdUtcSeconds: number, appleDate: string | null): File {
  const mvhd = box('mvhd', [0, 0, 0, 0], u32(createdUtcSeconds + 2_082_844_800), u32(0), new Array(88).fill(0));
  const meta = appleDate === null ? [] : box('meta',
    box('hdlr', new Array(8).fill(0), ascii('mdta'), new Array(12).fill(0), [0]),
    box('keys', u32(0), u32(2),
      [...u32(8 + 'com.apple.quicktime.make'.length), ...ascii('mdta'), ...ascii('com.apple.quicktime.make')],
      [...u32(8 + 'com.apple.quicktime.creationdate'.length), ...ascii('mdta'), ...ascii('com.apple.quicktime.creationdate')]),
    box('ilst',
      [...u32(8 + 16 + 5), ...u32(1), ...box('data', u32(1), u32(0), ascii('Apple'))],
      [...u32(8 + 16 + appleDate.length), ...u32(2), ...box('data', u32(1), u32(0), ascii(appleDate))]));
  const bytes = [...box('ftyp', ascii('qt  '), u32(0)), ...box('mdat', new Array(64).fill(7)), ...box('moov', mvhd, meta)];
  return new File([new Uint8Array(bytes)], 'IMG.MOV', { type: 'video/quicktime' });
}

describe('capture time', () => {
  it('reads a photo with an EXIF offset as that instant', async () => {
    await expect(readCaptureTime(jpegWithExif('2026:09:27 17:04:12', '-07:00'), 'photo')).resolves.toBe('2026-09-28T00:04:12.000Z');
  });

  it('sends an offset-less photo time as wall-clock time for the event zone', async () => {
    await expect(readCaptureTime(jpegWithExif('2026:09:27 17:04:12', null), 'photo')).resolves.toBe('2026-09-27T17:04:12');
  });

  it('prefers Apple\'s creation date in a video over the movie header', async () => {
    const header = Date.UTC(2026, 9, 1, 12) / 1000; // e.g. when Photos exported the copy
    await expect(readCaptureTime(movie(header, '2026-09-27T17:04:12-0700'), 'video')).resolves.toBe('2026-09-28T00:04:12.000Z');
  });

  it('falls back to the movie header creation time (UTC)', async () => {
    const header = Date.UTC(2026, 8, 27, 23, 30) / 1000;
    await expect(readCaptureTime(movie(header, null), 'video')).resolves.toBe('2026-09-27T23:30:00.000Z');
  });

  it('treats a missing, reset or future clock as unknown', async () => {
    await expect(readCaptureTime(new File(['not a photo'], 'x.jpg', { type: 'image/jpeg' }), 'photo')).resolves.toBeNull();
    await expect(readCaptureTime(jpegWithExif('1970:01:01 00:00:00', null), 'photo')).resolves.toBeNull();
    await expect(readCaptureTime(jpegWithExif('2099:01:01 00:00:00', '+00:00'), 'photo')).resolves.toBeNull();
    await expect(readCaptureTime(movie(0, null), 'video')).resolves.toBeNull();
  });

  it('ignores a malformed offset rather than misreading the time', () => {
    expect(parseExifDate('2026:09:27 17:04:12', 'local')).toBe('2026-09-27T17:04:12');
  });

  it('pairs each EXIF date only with its own offset tag', async () => {
    // OffsetTime (0x9010) is the modification date's offset, e.g. an edit
    // made in another time zone; it must not shift the capture time.
    const edited = jpegWithTags({ 0x9003: '2026:09:27 17:04:12', 0x9010: '+09:00' });
    await expect(readCaptureTime(edited, 'photo')).resolves.toBe('2026-09-27T17:04:12');

    const digitized = jpegWithTags({ 0x9004: '2026:09:27 17:04:12', 0x9010: '+09:00', 0x9012: '-07:00' });
    await expect(readCaptureTime(digitized, 'photo')).resolves.toBe('2026-09-28T00:04:12.000Z');
  });

  it('falls back to the next source when the preferred one is implausible', async () => {
    const resetOriginal = jpegWithTags({ 0x9003: '1970:01:01 00:00:00', 0x9004: '2026:09:27 17:04:12', 0x9012: '-07:00' });
    await expect(readCaptureTime(resetOriginal, 'photo')).resolves.toBe('2026-09-28T00:04:12.000Z');

    const header = Date.UTC(2026, 8, 27, 23, 30) / 1000;
    await expect(readCaptureTime(movie(header, '2099-01-01T00:00:00-0700'), 'video')).resolves.toBe('2026-09-27T23:30:00.000Z');
  });
});
