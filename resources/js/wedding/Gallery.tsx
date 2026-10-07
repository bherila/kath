import { Download, ImageIcon, Layers, Play, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { safeSameOriginUrl } from '@/security/dom-url';
import { type GalleryItem, type GalleryPage, requestJson, type SimilarList } from '@/wedding/api';
import { HlsVideoPlayer } from '@/wedding/HlsVideoPlayer';

interface GalleryProps {
  /** Bumped by the parent to reload from the newest item. */
  refreshKey: number;
}

export function Gallery({ refreshKey }: GalleryProps) {
  const [items, setItems] = useState<GalleryItem[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // The gallery tile that was opened, and the copy of it being viewed (the
  // tile itself, or one of the near-identical copies collapsed under it).
  const [open, setOpen] = useState<GalleryItem | null>(null);
  const [viewing, setViewing] = useState<GalleryItem | null>(null);

  // Each refresh starts a new generation; responses from an older one (a
  // superseded refresh, or a load-more that a refresh overtook) are dropped
  // so they can't overwrite or duplicate the newer page.
  const generation = useRef(0);

  const load = useCallback(async (cursor: string | null) => {
    if (cursor === null) {
      generation.current += 1;
    }
    const requestGeneration = generation.current;
    setLoading(true);
    setError(null);
    try {
      const url = cursor === null ? '/wedding/api/gallery' : `/wedding/api/gallery?cursor=${encodeURIComponent(cursor)}`;
      const page = await requestJson<GalleryPage>('GET', url);
      if (requestGeneration !== generation.current) {
        return;
      }
      setItems((current) => (cursor === null ? page.items : [...current, ...page.items]));
      setNextCursor(page.next_cursor);
    } catch (err) {
      if (requestGeneration === generation.current) {
        setError(err instanceof Error ? err.message : 'Could not load the gallery.');
      }
    } finally {
      if (requestGeneration === generation.current) {
        setLoading(false);
      }
    }
  }, []);

  useEffect(() => {
    void load(null);
  }, [load, refreshKey]);

  const openItem = (item: GalleryItem | null): void => {
    setOpen(item);
    setViewing(item);
  };

  const remove = async (item: GalleryItem): Promise<void> => {
    if (!window.confirm('Remove this from the gallery?')) {
      return;
    }
    try {
      await requestJson('DELETE', `/wedding/api/uploads/${item.ulid}`);
      if (open !== null && open.similar_count > 0) {
        // A cluster changed: another copy may now be the one shown.
        void load(null);
      } else {
        setItems((current) => current.filter((candidate) => candidate.ulid !== item.ulid));
      }
      openItem(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not remove it.');
    }
  };

  return (
    <div>
      {error !== null && <p className="mb-3 text-sm text-destructive">{error}</p>}

      {items.length === 0 && !loading && error === null && (
        <p className="text-sm text-muted-foreground">Nothing here yet — be the first to share!</p>
      )}

      <ul className="grid grid-cols-3 gap-1 sm:grid-cols-4 md:grid-cols-5">
        {items.map((item) => (
          <li key={item.ulid}>
            <button
              type="button"
              className="relative block aspect-square w-full overflow-hidden rounded-sm bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              onClick={() => openItem(item)}
              aria-label={`Open ${item.kind}${item.guest_name ? ` from ${item.guest_name}` : ''}`}
            >
              <Thumb item={item} />
              {item.similar_count > 0 && (
                <span className="absolute right-1 bottom-1 flex items-center gap-0.5 rounded bg-black/60 px-1 text-xs text-white">
                  <Layers className="size-3" aria-hidden="true" />+{item.similar_count}
                  <span className="sr-only"> similar</span>
                </span>
              )}
              {item.kind === 'video' && (
                <span className="absolute inset-0 flex items-center justify-center">
                  <span className="rounded-full bg-black/55 p-2 text-white">
                    <Play className="size-5" aria-hidden="true" />
                  </span>
                </span>
              )}
            </button>
          </li>
        ))}
      </ul>

      {nextCursor !== null && (
        <div className="mt-4 text-center">
          <Button type="button" variant="outline" disabled={loading} onClick={() => void load(nextCursor)}>
            {loading ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}

      <Dialog open={open !== null} onOpenChange={(next) => !next && openItem(null)}>
        {open !== null && viewing !== null && (
          <DialogContent className="max-w-[calc(100%-1rem)] gap-3 p-3 sm:max-w-3xl">
            <DialogTitle className="text-base">
              {viewing.guest_name ? `Shared by ${viewing.guest_name}` : 'Shared by a guest'}
            </DialogTitle>
            <DialogDescription className="sr-only">
              {viewing.kind === 'video' ? 'Video' : 'Photo'} from the wedding gallery
            </DialogDescription>
            <Viewer key={viewing.ulid} item={viewing} />
            {open.similar_count > 0 && <SimilarCopies item={open} viewing={viewing} onView={setViewing} />}
            <div className="flex flex-wrap justify-end gap-2">
              {viewing.mine && (
                <Button type="button" variant="ghost" size="sm" onClick={() => void remove(viewing)}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  Remove
                </Button>
              )}
              <SafeLinkButton href={viewing.original_url}>
                <Download className="size-4" aria-hidden="true" />
                Download original{dimensionsLabel(viewing)}
              </SafeLinkButton>
            </div>
          </DialogContent>
        )}
      </Dialog>
    </div>
  );
}

interface ItemProps {
  item: GalleryItem;
}

function dimensionsLabel(item: GalleryItem): string {
  return item.width !== null && item.height !== null ? ` (${item.width}×${item.height})` : '';
}

interface SimilarCopiesProps {
  /** The gallery tile (the best copy). */
  item: GalleryItem;
  viewing: GalleryItem;
  onView: (item: GalleryItem) => void;
}

/**
 * The near-identical copies collapsed under a gallery photo (e.g. a resized or
 * re-sent version), loaded on demand. The tile always shows the best copy.
 */
function SimilarCopies({ item, viewing, onView }: SimilarCopiesProps) {
  const [copies, setCopies] = useState<GalleryItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const show = async (): Promise<void> => {
    setError(null);
    try {
      const list = await requestJson<SimilarList>('GET', `/wedding/api/gallery/${item.ulid}/similar`);
      setCopies(list.items);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the other copies.');
    }
  };

  if (copies === null) {
    return (
      <div className="text-sm">
        <Button type="button" variant="link" size="sm" className="h-auto p-0" onClick={() => void show()}>
          <Layers className="size-4" aria-hidden="true" />
          {item.similar_count === 1 ? '1 similar copy' : `${item.similar_count} similar copies`} (showing the best)
        </Button>
        {error !== null && <p className="text-destructive">{error}</p>}
      </div>
    );
  }

  return (
    <ul className="flex gap-1 overflow-x-auto" aria-label="Similar copies">
      {[item, ...copies].map((copy) => (
        <li key={copy.ulid} className="shrink-0">
          <button
            type="button"
            className={`block size-16 overflow-hidden rounded-sm bg-muted ${copy.ulid === viewing.ulid ? 'ring-2 ring-primary' : ''}`}
            aria-label={`View ${copy === item ? 'best copy' : 'copy'}${dimensionsLabel(copy)}`}
            aria-pressed={copy.ulid === viewing.ulid}
            onClick={() => onView(copy)}
          >
            <Thumb item={copy} />
          </button>
        </li>
      ))}
    </ul>
  );
}

function Thumb({ item }: ItemProps) {
  const src = safeSameOriginUrl(item.thumb_url);
  if (src === null) {
    return (
      <span className="flex h-full w-full items-center justify-center text-muted-foreground">
        <ImageIcon className="size-6" aria-hidden="true" />
      </span>
    );
  }
  return <img src={src} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />;
}

function Viewer({ item }: ItemProps) {
  const [failed, setFailed] = useState(false);

  if (item.kind === 'video') {
    if (item.master_url === null || failed) {
      return (
        <p className="rounded-md bg-muted p-6 text-center text-sm text-muted-foreground">
          This video is still being prepared for streaming. Check back in a little while, or download the original.
        </p>
      );
    }
    return <HlsVideoPlayer src={item.master_url} className="max-h-[70vh] w-full rounded-md bg-black" onError={() => setFailed(true)} />;
  }

  const src = safeSameOriginUrl(item.display_url);
  if (src === null) {
    return null;
  }
  return <img src={src} alt="" className="max-h-[75vh] w-full rounded-md object-contain" />;
}

interface SafeLinkButtonProps {
  href: string;
  children: React.ReactNode;
}

function SafeLinkButton({ href, children }: SafeLinkButtonProps) {
  const safe = safeSameOriginUrl(href);
  if (safe === null) {
    return null;
  }
  return (
    <a href={safe} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
      {children}
    </a>
  );
}
