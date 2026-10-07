import { useEffect, useRef, useState } from 'react';

/**
 * Keep the screen on while `active`: a phone that locks mid-upload suspends
 * the page and stalls (or kills) a long video upload. Best-effort — browsers
 * without the Screen Wake Lock API just don't get it. The lock is dropped
 * whenever the page is hidden, so it is re-requested on return.
 */
export function useScreenWakeLock(active: boolean): void {
  useEffect(() => {
    if (!active || typeof navigator === 'undefined' || !('wakeLock' in navigator)) {
      return;
    }

    let sentinel: WakeLockSentinel | null = null;
    let cancelled = false;

    const acquire = async (): Promise<void> => {
      if (document.visibilityState !== 'visible' || (sentinel !== null && !sentinel.released)) {
        return;
      }
      try {
        const lock = await navigator.wakeLock.request('screen');
        if (cancelled) {
          void lock.release();
        } else {
          sentinel = lock;
        }
      } catch {
        // Denied (e.g. low battery mode); uploads continue without it.
      }
    };

    void acquire();
    document.addEventListener('visibilitychange', acquire);

    return () => {
      cancelled = true;
      document.removeEventListener('visibilitychange', acquire);
      void sentinel?.release().catch(() => {});
    };
  }, [active]);
}

/** How often the transfer rate is sampled, and how far back it looks. */
const SAMPLE_MS = 2_000;
const WINDOW_MS = 20_000;

/**
 * Bytes per second moved over the last WINDOW_MS while `active`, or null
 * until there's enough history to say. Feeds the "about N min left" estimate.
 */
export function useTransferRate(transferredBytes: number, active: boolean): number | null {
  const latest = useRef(transferredBytes);
  useEffect(() => {
    latest.current = transferredBytes;
  }, [transferredBytes]);
  const [rate, setRate] = useState<number | null>(null);

  useEffect(() => {
    if (!active) {
      setRate(null);
      return;
    }

    const samples: Array<{ at: number; bytes: number }> = [{ at: Date.now(), bytes: latest.current }];
    const timer = window.setInterval(() => {
      const now = Date.now();
      samples.push({ at: now, bytes: latest.current });
      while (samples.length > 2 && now - (samples[0]?.at ?? now) > WINDOW_MS) {
        samples.shift();
      }
      const first = samples[0];
      const elapsed = first === undefined ? 0 : now - first.at;
      setRate(first === undefined || elapsed < 3 * SAMPLE_MS ? null : Math.max(0, (latest.current - first.bytes) / (elapsed / 1000)));
    }, SAMPLE_MS);

    return () => window.clearInterval(timer);
  }, [active]);

  return rate;
}
