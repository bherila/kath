import { Check, CircleAlert, ImagePlus, Loader2, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
  computeFileHash,
  type FileKind,
  findExistingHashes,
  kindFor,
  uploadFile,
  type UploadLimits,
} from '@/wedding/uploader';

/** Files uploading at once; more just competes for a phone's uplink. */
const CONCURRENCY = 2;

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
    return `${(bytes / 1024 ** 3).toFixed(0)} GB`;
  }
  return `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

export function UploadPanel({ limits, onUploaded }: UploadPanelProps) {
  const [items, setItems] = useState<QueueItem[]>([]);
  const nextId = useRef(1);
  const abortRef = useRef<AbortController | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);

  const active = items.some((item) => item.status === 'hashing' || item.status === 'queued' || item.status === 'uploading');

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

  const runUploads = useCallback(async (queue: QueueItem[]) => {
    const controller = abortRef.current ?? new AbortController();
    abortRef.current = controller;
    let uploaded = 0;
    let cursor = 0;

    const worker = async (): Promise<void> => {
      while (cursor < queue.length) {
        const item = queue[cursor];
        cursor += 1;
        if (item === undefined || item.kind === null) {
          continue;
        }
        patch(item.id, { status: 'uploading', progress: 0, error: null });
        try {
          const outcome = await uploadFile(
            item.file,
            item.kind,
            item.hash,
            (fraction) => patch(item.id, { progress: fraction }),
            controller.signal,
          );
          patch(item.id, { status: outcome === 'duplicate' ? 'duplicate' : 'done', progress: 1 });
          if (outcome === 'uploaded') {
            uploaded += 1;
          }
        } catch (err) {
          patch(item.id, { status: 'failed', error: err instanceof Error ? err.message : 'Upload failed.' });
        }
      }
    };

    await Promise.all(Array.from({ length: CONCURRENCY }, () => worker()));
    if (uploaded > 0) {
      onUploaded();
    }
  }, [onUploaded, patch]);

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

    // Hash one at a time: each read holds the whole file in memory.
    const candidates = added.filter((item) => item.status === 'hashing');
    for (const item of candidates) {
      item.hash = await computeFileHash(item.file);
    }

    // Skip anything already in the gallery, and repeats within this selection.
    const existing = await findExistingHashes(candidates.flatMap((item) => (item.hash === null ? [] : [item.hash])));
    const seen = new Set<string>();
    const queue: QueueItem[] = [];
    for (const item of candidates) {
      if (item.hash !== null && (existing.has(item.hash) || seen.has(item.hash))) {
        patch(item.id, { status: 'duplicate', hash: item.hash });
        continue;
      }
      if (item.hash !== null) {
        seen.add(item.hash);
      }
      patch(item.id, { status: 'queued', hash: item.hash });
      queue.push(item);
    }

    await runUploads(queue);
  }, [limits, patch, runUploads]);

  const retry = (item: QueueItem): void => {
    void runUploads([item]);
  };

  const onInputChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
    const files = Array.from(event.target.files ?? []);
    // Reset so selecting the same files again still fires a change event.
    event.target.value = '';
    void addFiles(files);
  };

  const doneCount = items.filter((item) => item.status === 'done').length;
  const duplicateCount = items.filter((item) => item.status === 'duplicate').length;

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
      <input
        ref={inputRef}
        type="file"
        multiple
        accept="image/*,video/*"
        className="sr-only"
        aria-label="Choose photos and videos"
        onChange={onInputChange}
      />
      <Button type="button" size="lg" className="h-14 w-full text-base" onClick={() => inputRef.current?.click()}>
        <ImagePlus className="size-5" aria-hidden="true" />
        Add photos &amp; videos
      </Button>
      <p className="mt-2 text-center text-sm text-muted-foreground">
        Select as many as you like from your camera roll. Anything already shared is skipped automatically.
      </p>

      {items.length > 0 && (
        <div className="mt-4">
          <p className="text-sm font-medium" aria-live="polite">
            {active ? 'Uploading… keep this page open.' : 'All done — thank you!'}
            {' '}
            <span className="text-muted-foreground font-normal">
              {doneCount} shared{duplicateCount > 0 ? `, ${duplicateCount} already there` : ''}
            </span>
          </p>
          <ul className="mt-2 max-h-80 space-y-1.5 overflow-y-auto">
            {items.map((item) => (
              <li key={item.id} className="flex items-center gap-3 rounded-md bg-muted/50 px-3 py-2 text-sm">
                <StatusIcon status={item.status} />
                <div className="min-w-0 flex-1">
                  <p className="truncate">{item.file.name}</p>
                  {item.status === 'uploading' && (
                    <progress className="mt-1 h-1.5 w-full accent-primary" max={1} value={item.progress} />
                  )}
                  <p className="text-xs text-muted-foreground">{statusLabel(item)}</p>
                </div>
                {item.status === 'failed' && (
                  <Button type="button" variant="ghost" size="sm" onClick={() => retry(item)}>
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
      return 'Checking…';
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
