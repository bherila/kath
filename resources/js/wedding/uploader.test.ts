import { contentTypeFor, kindFor, type UploadLimits } from '@/wedding/uploader';

const limits: UploadLimits = {
  photo_bytes: 50,
  video_bytes: 500,
  photo_types: ['image/jpeg', 'image/heic'],
  video_types: ['video/quicktime'],
};

function file(name: string, type: string): File {
  return new File(['x'], name, { type });
}

describe('upload file classification', () => {
  it('uses the browser-reported type when present', () => {
    expect(contentTypeFor(file('a.bin', 'IMAGE/JPEG'))).toBe('image/jpeg');
  });

  it('falls back to the extension when the type is empty (HEIC on some browsers)', () => {
    expect(contentTypeFor(file('IMG_0001.HEIC', ''))).toBe('image/heic');
    expect(kindFor(file('IMG_0001.HEIC', ''), limits)).toBe('photo');
  });

  it('classifies videos and rejects anything else', () => {
    expect(kindFor(file('clip.mov', 'video/quicktime'), limits)).toBe('video');
    expect(kindFor(file('notes.pdf', 'application/pdf'), limits)).toBeNull();
    expect(kindFor(file('noext', ''), limits)).toBeNull();
  });
});
