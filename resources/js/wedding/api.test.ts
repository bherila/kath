import { describeFile } from '@/wedding/api';

describe('describeFile', () => {
  it('reports only known media extensions, never other parts of a name', () => {
    expect(describeFile(new File(['x'], 'IMG_0001.HEIC', { type: 'image/heic' }))).toEqual({ type: 'image/heic', ext: 'heic', size: 1 });
    expect(describeFile(new File(['x'], 'photo.JohnSmith', { type: '' })).ext).toBe('');
    expect(describeFile(new File(['x'], '.Alice', { type: '' })).ext).toBe('');
    expect(describeFile(new File(['x'], 'noextension', { type: '' })).ext).toBe('');
  });
});
