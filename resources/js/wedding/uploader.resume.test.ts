import { requestJson } from '@/wedding/api';
import { readMultipartSession, uploadMultipartFile } from '@/wedding/upload';
import { uploadFile } from '@/wedding/uploader';

jest.mock('@/wedding/api', () => ({
  ...jest.requireActual('@/wedding/api'),
  requestJson: jest.fn(),
}));
jest.mock('@/wedding/upload', () => ({
  ...jest.requireActual('@/wedding/upload'),
  readMultipartSession: jest.fn(),
  saveMultipartSession: jest.fn(),
  uploadMultipartFile: jest.fn(),
  putToSignedUrl: jest.fn(),
}));
jest.mock('@/wedding/imageProcessing', () => ({
  ...jest.requireActual('@/wedding/imageProcessing'),
  supportsClientDerivatives: () => false,
}));

const request = jest.mocked(requestJson);
const readSession = jest.mocked(readMultipartSession);
const multipart = jest.mocked(uploadMultipartFile);

const savedSession = {
  uploadUlid: 'old',
  uploadId: 'up-old',
  partSizeBytes: 16,
  completedParts: [{ part_number: 1, etag: '"a"' }],
  createdAt: '2026-10-04T00:00:00Z',
};

function video(): File {
  return new File(['x'.repeat(64)], 'IMG_0001.MOV', { type: 'video/quicktime', lastModified: 1 });
}

describe('multipart resume', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    Object.defineProperty(globalThis, 'crypto', { value: { randomUUID: () => 'uuid' }, configurable: true });
    readSession.mockReturnValue(savedSession);
    multipart.mockResolvedValue(undefined);
    request.mockImplementation(async (_method, url) => {
      if (url === '/wedding/api/uploads') {
        return { ulid: 'new', multipart: true, upload_url: '', upload_headers: {}, display_upload: null, thumbnail_upload: null };
      }
      return { upload_id: 'up-new', part_size_bytes: 16, max_part_number: 4 };
    });
  });

  it('resumes a saved session for the same content hash', async () => {
    await uploadFile(video(), 'video', 'hash-a', () => {}, new AbortController().signal);

    expect(readSession).toHaveBeenCalledWith('wedding-multipart:hash-a');
    expect(request).not.toHaveBeenCalledWith('POST', '/wedding/api/uploads', expect.anything());
    expect(multipart.mock.calls[0]?.[1].session).toBe(savedSession);
  });

  it('never resumes an unhashed file, even with matching name, size and date', async () => {
    await uploadFile(video(), 'video', null, () => {}, new AbortController().signal);

    expect(readSession).not.toHaveBeenCalled();
    expect(request).toHaveBeenCalledWith('POST', '/wedding/api/uploads', expect.anything());
    expect(multipart.mock.calls[0]?.[1].session.uploadId).toBe('up-new');
    expect(multipart.mock.calls[0]?.[1].session.completedParts).toEqual([]);
  });
});
