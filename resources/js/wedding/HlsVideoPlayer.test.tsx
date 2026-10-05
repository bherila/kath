import { render, waitFor } from '@testing-library/react';

import { HlsVideoPlayer } from '@/wedding/HlsVideoPlayer';

jest.mock('hls.js', () => ({
  __esModule: true,
  default: {
    isSupported: () => false,
  },
}));

describe('HlsVideoPlayer source validation', () => {
  beforeEach(() => {
    jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably');
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('sets a same-origin manifest on native HLS playback', () => {
    const { container } = render(<HlsVideoPlayer src="/wedding/hls/ceremony/master.m3u8" />);

    expect(container.querySelector('video')).toHaveAttribute(
      'src',
      'http://localhost/wedding/hls/ceremony/master.m3u8',
    );
  });

  it('rejects script URLs before assigning the video source', async () => {
    const onError = jest.fn();
    const { container } = render(<HlsVideoPlayer src="javascript:alert(1)" onError={onError} />);

    expect(container.querySelector('video')).not.toHaveAttribute('src');
    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  });
});

describe('HlsVideoPlayer without native HLS', () => {
  beforeEach(() => {
    jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('');
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('reports an error when neither native HLS nor MSE is available', async () => {
    const onError = jest.fn();
    render(<HlsVideoPlayer src="/wedding/hls/ceremony/master.m3u8" onError={onError} />);

    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  });
});
