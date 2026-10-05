import { useEffect, useRef } from 'react';

import { safeSameOriginUrl } from '@/security/dom-url';

interface HlsVideoPlayerProps {
  /** App-relative URL of the HLS master playlist proxy (`…/hls/master.m3u8`). */
  src: string;
  className?: string | undefined;
  /**
   * Called when HLS playback fails unrecoverably (manifest/segment 404, stale
   * mapping, transient failure, or no HLS/MSE support). Lets the parent mark
   * the stream unavailable without falling back to the original video file.
   */
  onError?: (() => void) | undefined;
}

/**
 * Plays an adaptive-bitrate HLS stream. Safari/iOS play HLS natively; everywhere
 * else we lazily attach hls.js (Media Source Extensions). `src` points at the
 * gated playback proxy, which serves manifests and 302-redirects segments
 * to presigned R2 URLs.
 */
export function HlsVideoPlayer({ src, className, onError }: HlsVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  // Hold the latest onError so a fresh callback identity each render doesn't tear
  // down and re-attach the player.
  const onErrorRef = useRef(onError);
  useEffect(() => {
    onErrorRef.current = onError;
  });

  useEffect(() => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    const safeSource = safeSameOriginUrl(src);
    if (safeSource === null) {
      onErrorRef.current?.();
      return;
    }

    // Native HLS (Safari, iOS): point the element at the manifest.
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = safeSource;
      const handleNativeError = () => onErrorRef.current?.();
      video.addEventListener('error', handleNativeError);
      return () => {
        video.removeEventListener('error', handleNativeError);
      };
    }

    // Everywhere else, load hls.js (Media Source Extensions) on demand so iOS
    // and Safari never download it.
    let cancelled = false;
    let destroy: (() => void) | null = null;
    void import('hls.js').then(({ default: Hls }) => {
      if (cancelled) {
        return;
      }
      if (!Hls.isSupported()) {
        // Neither native HLS nor MSE support.
        onErrorRef.current?.();
        return;
      }
      const hls = new Hls({ enableWorker: true });
      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (data.fatal) {
          onErrorRef.current?.();
        }
      });
      hls.loadSource(safeSource);
      hls.attachMedia(video);
      destroy = () => hls.destroy();
    }, () => {
      if (!cancelled) {
        onErrorRef.current?.();
      }
    });

    return () => {
      cancelled = true;
      destroy?.();
    };
  }, [src]);

  return <video ref={videoRef} controls playsInline className={className} />;
}
