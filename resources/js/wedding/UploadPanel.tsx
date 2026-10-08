import { Check, CircleAlert, ImagePlus, Loader2, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { describeFile, reportClientEvent } from '@/wedding/api';
import {
  computeFileHash,
  type FileKind,
  findExistingHashes,
  kindFor,
  uploadFile,
  type UploadLimits,
} from '@/wedding/uploader';
import { useScreenWakeLock, useTransferRate } from '@/wedding/uploadHooks';

/** Files uploading at once; more just competes for a phone's uplink. */
const CONCURRENCY = 2;

/**
 * A selection is hashed smallest file first and handed to the uploader in
 * groups, so photos start uploading while a long video is still being hashed.
 */
const HASH_GROUP_FILES = 10;
const HASH_GROUP_BYTES = 256 * 1024 * 1024;

/** Progress changes smaller than this aren't worth a re-render. */
const PROGRESS_STEP = 0.01;

const MULTIPART_SESSION_PREFIX = 'wedding-multipart:';

/**
 * How long after the page regains focus from the picker to wait for its
 * files: iOS converts photos (and fetches iCloud originals) before handing
 * them over, so the change event can lag the picker closing.
 */
const PICKER_GRACE_MS = 5_000;

const PICKER_EMPTY_NOTICE =
  'No photos came through from the picker. If you did choose some, please try again. If it keeps happening, open the photos in your Photos app first (so they download from iCloud), or use \u201cChoose File\u201d.';

type ItemStatus = 'hashing' | 'queued' | 'uploading' | 'done' | 'duplicate' | 'failed' | 'unsupported';

interface QueueItem {
  id: number;
  file: File;
  kind: FileKind | null;
  hash: string | null;
  status: ItemStatus;
  progress: number;
  error: string | null;
}

interface UploadPanelProps {
  limits: UploadLimits;
  /** Called after a batch finishes with at least one new upload. */
  onUploaded: () => void;
}

function formatBytes(bytes: number): string {
  if (bytes >= 1024 ** 3) {
    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
  }
  return `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

function formatDuration(seconds: number): string {
  if (seconds < 60) {
    return 'less than a minute left';
  }
  const minutes = Math.round(seconds / 60);
  if (minutes < 90) {
    return `about ${minutes} min left`;
  }
  return `about ${Math.round(minutes / 60)} hr left`;
}

/**
 * Batch totals over the files that will actually upload (not duplicates or
 * unsupported files): how many, and how many of their bytes have been sent.
 */
function transferTotals(items: QueueItem[]): { fileCount: number; totalBytes: number; sentBytes: number } {
  let fileCount = 0;
  let totalBytes = 0;
  let sentBytes = 0;
  for (const item of items) {
    if (item.status === 'duplicate' || item.status === 'unsupported') {
      continue;
    }
    fileCount += 1;
    totalBytes += item.file.size;
    if (item.status === 'done') {
      sentBytes += item.file.size;
    } else if (item.status === 'uploading') {
      sentBytes += item.file.size * item.progress;
    }
  }
  return { fileCount, totalBytes, sentBytes };
}

/** Large-video uploads saved for resuming (only hash-keyed ones can resume). */
function countUnfinishedSessions(): number {
  try {
    let count = 0;
    for (let i = 0; i < window.localStorage.length; i += 1) {
      const key = window.localStorage.key(i);
      if (key?.startsWith(MULTIPART_SESSION_PREFIX) && !key.startsWith(`${MULTIPART_SESSION_PREFIX}unverified:`)) {
        count += 1;
      }
    }
    return count;
  } catch {
    return 0;
  }
}

export function UploadPanel({ limits, onUploaded }: UploadPanelProps) {
  const [items, setItems] = useState<QueueItem[]>([]);
  const nextId = useRef(1);
  const abortRef = useRef<AbortController | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [pickerNotice, setPickerNotice] = useState<string | null>(null);
  // Set while the native picker is open, so its closing without delivering
  // any files (a cancel, or an OS hand-off that failed) can be noticed.
  const pickerOpen = useRef(false);
  const pickerTimer = useRef<number | undefined>(undefined);
  // The picker finished (or is reopening): a pending "nothing came back"
  // check belongs to the old invocation.
  const settlePicker = useCallback((open: boolean): void => {
    window.clearTimeout(pickerTimer.current);
    pickerTimer.current = undefined;
    pickerOpen.current = open;
  }, []);
  const [dragging, setDragging] = useState(false);

  const active = items.some((item) => item.status === 'hashing' || item.status === 'queued' || item.status === 'uploading');
  const itemsRef = useRef(items);
  useEffect(() => {
    itemsRef.current = items;
  }, [items]);
  const [unfinished] = useState(countUnfinishedSessions);

  useScreenWakeLock(active);

  useEffect(() => {
    if (!active) {
      return;
    }
    const warn = (event: BeforeUnloadEvent): void => {
      event.preventDefault();
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [active]);

  const patch = useCallback((id: number, changes: Partial<QueueItem>) => {
    setItems((current) => current.map((item) => (item.id === id ? { ...item, ...changes } : item)));
  }, []);

  // XHR reports progress many times a second; with dozens of rows, only
  // re-render when an item has moved a visible amount.
  const shownProgress = useRef(new Map<number, number>());
  const patchProgress = useCallback((id: number, fraction: number) => {
    const shown = shownProgress.current.get(id) ?? 0;
    if (fraction >= 1 || fraction < shown || fraction - shown >= PROGRESS_STEP) {
      shownProgress.current.set(id, fraction);
      patch(id, { progress: fraction });
    }
  }, [patch]);

  // One queue and one worker pool for the whole panel, so picking more files
  // (or retrying) while a batch is still uploading joins that batch instead of
  // starting a second set of workers.
  const queueRef = useRef<QueueItem[]>([]);
  const workersRef = useRef(0);
  const uploadedRef = useRef(0);
  // Items queued or uploading, so a repeated Retry can't enqueue one twice.
  const pendingRef = useRef(new Set<number>());

  const runUploads = useCallback((queue: QueueItem[]): void => {
    const controller = abortRef.current ?? new AbortController();
    abortRef.current = controller;
    for (const item of queue) {
      if (pendingRef.current.has(item.id)) {
        continue;
      }
      pendingRef.current.add(item.id);
      patch(item.id, { status: 'queued', error: null });
      queueRef.current.push(item);
    }

    const worker = async (): Promise<void> => {
      for (let item = queueRef.current.shift(); item !== undefined; item = queueRef.current.shift()) {
        const { id } = item;
        if (item.kind === null) {
          pendingRef.current.delete(id);
          continue;
        }
        shownProgress.current.set(id, 0);
        patch(id, { status: 'uploading', progress: 0, error: null });
        try {
          const outcome = await uploadFile(
            item.file,
            item.kind,
            item.hash,
            (fraction) => patchProgress(id, fraction),
            controller.signal,
          );
          patch(id, { status: outcome === 'duplicate' ? 'duplicate' : 'done', progress: 1 });
          if (outcome === 'uploaded') {
            uploadedRef.current += 1;
          }
        } catch (err) {
          const message = err instanceof Error ? err.message : 'Upload failed.';
          patch(id, { status: 'failed', error: message });
          if (!(err instanceof DOMException && err.name === 'AbortError')) {
            reportClientEvent('upload_failed', { message, files: [describeFile(item.file)] });
          }
        } finally {
          pendingRef.current.delete(id);
        }
      }
      workersRef.current -= 1;
      if (workersRef.current === 0 && uploadedRef.current > 0) {
        uploadedRef.current = 0;
        onUploaded();
      }
    };

    // Count the free slots first: a worker shifts its first item
    // synchronously, so the queue shrinks as each one starts.
    const toStart = Math.min(CONCURRENCY - workersRef.current, queueRef.current.length);
    for (let i = 0; i < toStart; i += 1) {
      workersRef.current += 1;
      void worker();
    }
  }, [onUploaded, patch, patchProgress]);

  const addFiles = useCallback(async (files: File[]) => {
    if (files.length === 0) {
      return;
    }

    const added: QueueItem[] = files.map((file) => {
      const kind = kindFor(file, limits);
      const tooBig = kind !== null && file.size > (kind === 'photo' ? limits.photo_bytes : limits.video_bytes);
      return {
        id: nextId.current++,
        file,
        kind: tooBig ? null : kind,
        hash: null,
        status: kind === null || tooBig ? 'unsupported' : 'hashing',
        progress: 0,
        error: tooBig ? `Larger than ${formatBytes(kind === 'photo' ? limits.photo_bytes : limits.video_bytes)}` : null,
      };
    });
    setItems((current) => [...added, ...current]);
    const rejected = added.filter((item) => item.status === 'unsupported');
    if (rejected.length > 0) {
      reportClientEvent('file_rejected', { count: rejected.length, files: rejected.slice(0, 20).map((item) => describeFile(item.file)) });
    }

    // Skip anything already in the gallery, and repeats within this selection.
    const seen = new Set<string>();
    const enqueue = async (group: QueueItem[]): Promise<void> => {
      const existing = await findExistingHashes(group.flatMap((item) => (item.hash === null ? [] : [item.hash])));
      const queue: QueueItem[] = [];
      for (const item of group) {
        if (item.hash !== null && (existing.has(item.hash) || seen.has(item.hash))) {
          patch(item.id, { status: 'duplicate', hash: item.hash, progress: 0 });
          continue;
        }
        if (item.hash !== null) {
          seen.add(item.hash);
        }
        patch(item.id, { hash: item.hash, progress: 0 });
        queue.push(item);
      }
      runUploads(queue);
    };

    // Hash one file at a time (large ones stream in chunks), smallest first,
    // handing each group over as soon as it's ready.
    const candidates = added
      .filter((item) => item.status === 'hashing')
      .sort((a, b) => a.file.size - b.file.size);
    let group: QueueItem[] = [];
    let groupBytes = 0;
    for (const item of candidates) {
      // Don't make the files already hashed wait on a big one.
      if (group.length > 0 && groupBytes + item.file.size > HASH_GROUP_BYTES) {
        await enqueue(group);
        group = [];
        groupBytes = 0;
      }
      shownProgress.current.set(item.id, 0);
      item.hash = await computeFileHash(item.file, (fraction) => patchProgress(item.id, fraction));
      group.push(item);
      groupBytes += item.file.size;
      if (group.length >= HASH_GROUP_FILES || groupBytes >= HASH_GROUP_BYTES) {
        await enqueue(group);
        group = [];
        groupBytes = 0;
      }
    }
    await enqueue(group);
  }, [limits, patch, patchProgress, runUploads]);

  const retry = (item: QueueItem): void => {
    runUploads([item]);
  };

  const retryAllFailed = useCallback((): void => {
    const failed = itemsRef.current.filter((item) => item.status === 'failed' && item.kind !== null);
    if (failed.length > 0) {
      runUploads(failed);
    }
  }, [runUploads]);

  // A phone that lost signal, or a tab the browser suspended in the
  // background, fails its uploads; pick them back up (a large video resumes
  // from its last finished part) as soon as the network or the page returns.
  useEffect(() => {
    const onVisible = (): void => {
      if (document.visibilityState === 'visible') {
        retryAllFailed();
      }
    };
    window.addEventListener('online', retryAllFailed);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      window.removeEventListener('online', retryAllFailed);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [retryAllFailed]);

  const pickerReturnedNothing = useCallback((reason: string): void => {
    settlePicker(false);
    setPickerNotice(PICKER_EMPTY_NOTICE);
    reportClientEvent('picker_empty', { reason });
  }, [settlePicker]);

  const onInputChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
    settlePicker(false);
    const files = Array.from(event.target.files ?? []);
    // Reset so selecting the same files again still fires a change event.
    event.target.value = '';
    if (files.length === 0) {
      pickerReturnedNothing('empty_change');
      return;
    }
    setPickerNotice(null);
    reportClientEvent('picker_change', { count: files.length, files: files.slice(0, 20).map(describeFile) });
    addFiles(files).catch((err: unknown) => {
      const message = err instanceof Error ? err.message : String(err);
      setPickerNotice(`Something went wrong preparing those files: ${message}`);
      reportClientEvent('uploader_error', { message });
    });
  };

  // The picker closed without a change event: browsers fire `cancel` (or, on
  // older ones, nothing but the page regaining focus).
  useEffect(() => {
    const input = inputRef.current;
    if (input === null) {
      return;
    }
    const onCancel = (): void => {
      if (pickerOpen.current) {
        pickerReturnedNothing('cancel');
      }
    };
    const onFocus = (): void => {
      if (!pickerOpen.current) {
        return;
      }
      window.clearTimeout(pickerTimer.current);
      pickerTimer.current = window.setTimeout(() => {
        if (pickerOpen.current) {
          pickerReturnedNothing('no_change');
        }
      }, PICKER_GRACE_MS);
    };
    input.addEventListener('cancel', onCancel);
    window.addEventListener('focus', onFocus);
    return () => {
      input.removeEventListener('cancel', onCancel);
      window.removeEventListener('focus', onFocus);
      window.clearTimeout(pickerTimer.current);
    };
  }, [pickerReturnedNothing]);

  const doneCount = items.filter((item) => item.status === 'done').length;
  const duplicateCount = items.filter((item) => item.status === 'duplicate').length;
  const failedCount = items.filter((item) => item.status === 'failed').length;
  const totals = transferTotals(items);
  const rate = useTransferRate(totals.sentBytes, active);
  const secondsLeft = rate !== null && rate > 0 ? (totals.totalBytes - totals.sentBytes) / rate : null;

  return (
    <div
      className={cn('rounded-xl border-2 border-dashed p-4 transition-colors', dragging ? 'border-primary bg-muted' : 'border-border')}
      onDragOver={(event) => {
        event.preventDefault();
        setDragging(true);
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(event) => {
        event.preventDefault();
        setDragging(false);
        void addFiles(Array.from(event.dataTransfer.files));
      }}
    >
      {/* The label is the button, so a tap lands on the file input itself:
          iOS opens (and hands back from) the photo picker more reliably than
          for a script-triggered click on a hidden input. */}
      <label className={cn(buttonVariants({ size: 'lg' }), 'h-14 w-full cursor-pointer text-base has-[input:focus-visible]:ring-[3px] has-[input:focus-visible]:ring-ring/50')}>
        <input
          ref={inputRef}
          type="file"
          multiple
          accept="image/*,video/*"
          className="sr-only"
          aria-label="Choose photos and videos"
          onClick={() => settlePicker(true)}
          onChange={onInputChange}
        />
        <ImagePlus className="size-5" aria-hidden="true" />
        Add photos &amp; videos
      </label>
      <p className="mt-2 text-center text-sm text-muted-foreground">
        Select as many as you like from your camera roll. Anything already shared is skipped automatically.
      </p>

      {pickerNotice !== null && (
        <p role="status" className="mt-3 rounded-md bg-muted p-3 text-sm">
          {pickerNotice}
        </p>
      )}

      {unfinished > 0 && items.length === 0 && (
        <p className="mt-3 rounded-md bg-muted p-3 text-sm">
          {unfinished === 1 ? 'A large video' : `${unfinished} large videos`} didn&apos;t finish uploading last time. Choose{' '}
          {unfinished === 1 ? 'it' : 'them'} again and the upload picks up where it left off.
        </p>
      )}

      {items.length > 0 && (
        <div className="mt-4">
          <p className="text-sm font-medium" aria-live="polite">
            {active ? 'Uploading… keep this page open and your screen on.' : failedCount > 0 ? 'Some uploads didn\u2019t finish.' : 'All done — thank you!'}
            {' '}
            <span className="text-muted-foreground font-normal">
              {doneCount} of {totals.fileCount} shared{duplicateCount > 0 ? `, ${duplicateCount} already there` : ''}
            </span>
          </p>
          {totals.totalBytes > 0 && (active || totals.sentBytes < totals.totalBytes) && (
            <div className="mt-2">
              <progress
                className="h-2 w-full accent-primary"
                max={totals.totalBytes}
                value={totals.sentBytes}
                aria-label="Overall upload progress"
              />
              <p className="text-xs text-muted-foreground">
                {formatBytes(totals.sentBytes)} of {formatBytes(totals.totalBytes)}
                {active && secondsLeft !== null ? ` · ${formatDuration(secondsLeft)}` : ''}
              </p>
            </div>
          )}
          {failedCount > 0 && !active && (
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={retryAllFailed}>
              <RotateCcw className="size-4" aria-hidden="true" />
              Retry {failedCount === 1 ? 'the failed upload' : `all ${failedCount} failed`}
            </Button>
          )}
          <ul className="mt-2 max-h-80 space-y-1.5 overflow-y-auto">
            {items.map((item) => (
              <li key={item.id} className="flex items-center gap-3 rounded-md bg-muted/50 px-3 py-2 text-sm">
                <StatusIcon status={item.status} />
                <div className="min-w-0 flex-1">
                  <p className="truncate">{item.file.name}</p>
                  {(item.status === 'uploading' || (item.status === 'hashing' && item.progress > 0)) && (
                    <progress className="mt-1 h-1.5 w-full accent-primary" max={1} value={item.progress} />
                  )}
                  <p className="text-xs text-muted-foreground">{statusLabel(item)}</p>
                </div>
                {item.status === 'failed' && (
                  <Button type="button" variant="ghost" size="sm" aria-label={`Retry ${item.file.name}`} onClick={() => retry(item)}>
                    <RotateCcw className="size-4" aria-hidden="true" />
                    Retry
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function statusLabel(item: QueueItem): string {
  switch (item.status) {
    case 'hashing':
      return item.progress > 0 && item.progress < 1 ? `Checking… ${Math.round(item.progress * 100)}%` : 'Checking…';
    case 'queued':
      return 'Waiting…';
    case 'uploading':
      return `${Math.round(item.progress * 100)}%`;
    case 'done':
      return 'Shared';
    case 'duplicate':
      return 'Already shared ✓';
    case 'unsupported':
      return item.error ?? 'Not a photo or video';
    case 'failed':
      return item.error ?? 'Upload failed';
  }
}

interface StatusIconProps {
  status: ItemStatus;
}

function StatusIcon({ status }: StatusIconProps) {
  if (status === 'done' || status === 'duplicate') {
    return <Check className="size-4 shrink-0 text-green-600" aria-hidden="true" />;
  }
  if (status === 'failed' || status === 'unsupported') {
    return <CircleAlert className="size-4 shrink-0 text-destructive" aria-hidden="true" />;
  }
  return <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" aria-hidden="true" />;
}
