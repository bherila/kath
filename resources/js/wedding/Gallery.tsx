import { Download, ImageIcon, Play, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { safeSameOriginUrl } from '@/security/dom-url';
import { type GalleryItem, type GalleryPage, requestJson } from '@/wedding/api';
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
  const [open, setOpen] = useState<GalleryItem | null>(null);

  const load = useCallback(async (cursor: string | null) => {
    setLoading(true);
    setError(null);
    try {
      const url = cursor === null ? '/wedding/api/gallery' : `/wedding/api/gallery?cursor=${encodeURIComponent(cursor)}`;
      const page = await requestJson<GalleryPage>('GET', url);
      setItems((current) => (cursor === null ? page.items : [...current, ...page.items]));
      setNextCursor(page.next_cursor);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the gallery.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load(null);
  }, [load, refreshKey]);

  const remove = async (item: GalleryItem): Promise<void> => {
    if (!window.confirm('Remove this from the gallery?')) {
      return;
    }
    try {
      await requestJson('DELETE', `/wedding/api/uploads/${item.ulid}`);
      setItems((current) => current.filter((candidate) => candidate.ulid !== item.ulid));
      setOpen(null);
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
              onClick={() => setOpen(item)}
              aria-label={`Open ${item.kind}${item.guest_name ? ` from ${item.guest_name}` : ''}`}
            >
              <Thumb item={item} />
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

      <Dialog open={open !== null} onOpenChange={(next) => !next && setOpen(null)}>
        {open !== null && (
          <DialogContent className="max-w-[calc(100%-1rem)] gap-3 p-3 sm:max-w-3xl">
            <DialogTitle className="text-base">
              {open.guest_name ? `Shared by ${open.guest_name}` : 'Shared by a guest'}
            </DialogTitle>
            <DialogDescription className="sr-only">
              {open.kind === 'video' ? 'Video' : 'Photo'} from the wedding gallery
            </DialogDescription>
            <Viewer item={open} />
            <div className="flex flex-wrap justify-end gap-2">
              {open.mine && (
                <Button type="button" variant="ghost" size="sm" onClick={() => void remove(open)}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  Remove
                </Button>
              )}
              <SafeLinkButton href={open.original_url}>
                <Download className="size-4" aria-hidden="true" />
                Download original
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
