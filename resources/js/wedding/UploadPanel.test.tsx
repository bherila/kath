import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import * as uploader from '@/wedding/uploader';
import { UploadPanel } from '@/wedding/UploadPanel';

jest.mock('@/wedding/uploader', () => {
  const actual = jest.requireActual<typeof import('@/wedding/uploader')>('@/wedding/uploader');
  return {
    ...actual,
    computeFileHash: jest.fn(),
    findExistingHashes: jest.fn(),
    uploadFile: jest.fn(),
  };
});

const mocked = jest.mocked(uploader);

const limits: uploader.UploadLimits = {
  photo_bytes: 1000,
  video_bytes: 1000,
  photo_types: ['image/jpeg'],
  video_types: ['video/mp4'],
};

function photo(name: string): File {
  return new File([name], name, { type: 'image/jpeg' });
}

function choose(files: File[]): void {
  fireEvent.change(screen.getByLabelText('Choose photos and videos'), { target: { files } });
}

describe('UploadPanel', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    mocked.computeFileHash.mockImplementation(async (file: File) => `hash-${file.name.replace(/\d$/, '')}`);
  });

  it('skips files already in the gallery and repeats within one selection', async () => {
    mocked.findExistingHashes.mockResolvedValue(new Set(['hash-old.jpg']));
    mocked.uploadFile.mockResolvedValue('uploaded');
    const onUploaded = jest.fn();

    render(<UploadPanel limits={limits} onUploaded={onUploaded} />);
    // "new.jpg" and "new.jpg2" hash identically (same content selected twice).
    choose([photo('old.jpg'), photo('new.jpg'), photo('new.jpg2')]);

    await waitFor(() => expect(onUploaded).toHaveBeenCalledTimes(1));
    expect(mocked.uploadFile).toHaveBeenCalledTimes(1);
    expect(mocked.uploadFile.mock.calls[0]?.[0].name).toBe('new.jpg');
    expect(screen.getAllByText('Already shared ✓')).toHaveLength(2);
  });

  it('rejects unsupported and oversized files without uploading them', async () => {
    mocked.findExistingHashes.mockResolvedValue(new Set());
    render(<UploadPanel limits={limits} onUploaded={jest.fn()} />);

    choose([
      new File(['x'], 'notes.pdf', { type: 'application/pdf' }),
      new File([new Uint8Array(2000)], 'huge.jpg', { type: 'image/jpeg' }),
    ]);

    expect(await screen.findByText('Not a photo or video')).toBeInTheDocument();
    expect(screen.getByText(/Larger than/)).toBeInTheDocument();
    expect(mocked.uploadFile).not.toHaveBeenCalled();
  });

  it('marks a failed upload and retries it on request', async () => {
    mocked.findExistingHashes.mockResolvedValue(new Set());
    mocked.uploadFile.mockRejectedValueOnce(new Error('Upload failed: network error.')).mockResolvedValueOnce('uploaded');
    const onUploaded = jest.fn();

    render(<UploadPanel limits={limits} onUploaded={onUploaded} />);
    choose([photo('a.jpg')]);

    expect(await screen.findByText('Upload failed: network error.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Retry/ }));

    await waitFor(() => expect(screen.getByText('Shared')).toBeInTheDocument());
    expect(onUploaded).toHaveBeenCalledTimes(1);
    // The retry reuses the hash computed for the first attempt.
    expect(mocked.uploadFile.mock.calls[1]?.[2]).toBe('hash-a.jpg');
  });
  it('keeps one upload pool across selections made while uploading', async () => {
    mocked.findExistingHashes.mockResolvedValue(new Set());
    mocked.computeFileHash.mockImplementation(async (file: File) => `hash-${file.name}`);
    let inFlight = 0;
    let peak = 0;
    const releases: Array<() => void> = [];
    mocked.uploadFile.mockImplementation(() => {
      inFlight += 1;
      peak = Math.max(peak, inFlight);
      return new Promise((resolve) => {
        releases.push(() => {
          inFlight -= 1;
          resolve('uploaded');
        });
      });
    });
    const onUploaded = jest.fn();

    render(<UploadPanel limits={limits} onUploaded={onUploaded} />);
    choose([photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]);
    await waitFor(() => expect(inFlight).toBe(2));
    choose([photo('d.jpg'), photo('e.jpg')]);
    await waitFor(() => expect(screen.getAllByText('Waiting…').length).toBeGreaterThanOrEqual(3));

    while (mocked.uploadFile.mock.calls.length < 5 || inFlight > 0) {
      await waitFor(() => expect(releases.length).toBeGreaterThan(0));
      releases.shift()?.();
    }

    await waitFor(() => expect(onUploaded).toHaveBeenCalledTimes(1));
    expect(peak).toBe(2);
    expect(mocked.uploadFile).toHaveBeenCalledTimes(5);
  });
});
