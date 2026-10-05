import './bootstrap';

import { Heart, LogOut } from 'lucide-react';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Gallery } from '@/wedding/Gallery';
import { HlsVideoPlayer } from '@/wedding/HlsVideoPlayer';
import type { UploadLimits } from '@/wedding/uploader';
import { UploadPanel } from '@/wedding/UploadPanel';

interface WeddingBootstrap {
  guest: { name: string | null; email: string };
  ceremony: { master_url: string | null };
  limits: UploadLimits;
}

interface WeddingHubProps {
  data: WeddingBootstrap;
}

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function WeddingHub({ data }: WeddingHubProps) {
  const [galleryKey, setGalleryKey] = useState(0);
  const [ceremonyFailed, setCeremonyFailed] = useState(false);

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 pb-8">
      <header className="pt-2 text-center">
        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Katherine &amp; Jack</h1>
        <p className="mt-1 flex items-center justify-center gap-1.5 text-muted-foreground">
          <Heart className="size-4" aria-hidden="true" />
          Wedding Hub
        </p>
        <p className="mt-3 text-sm text-muted-foreground">
          Welcome{data.guest.name ? `, ${data.guest.name}` : ''}!
        </p>
      </header>

      <Card id="ceremony">
        <CardHeader>
          <CardTitle>Watch the ceremony</CardTitle>
        </CardHeader>
        <CardContent>
          {data.ceremony.master_url !== null && !ceremonyFailed ? (
            <HlsVideoPlayer
              src={data.ceremony.master_url}
              // Size to the video itself: the ceremony (and guests' phone videos)
              // may be portrait, which a fixed 16:9 box would letterbox. Cap the
              // height so a portrait video stays on one screen.
              className="mx-auto max-h-[80vh] w-full rounded-md bg-black object-contain"
              onError={() => setCeremonyFailed(true)}
            />
          ) : (
            <p className="rounded-md bg-muted p-6 text-center text-sm text-muted-foreground">
              The ceremony video is coming soon. Check back here!
            </p>
          )}
        </CardContent>
      </Card>

      <Card id="share">
        <CardHeader>
          <CardTitle>Share your photos &amp; videos</CardTitle>
          <CardDescription>Help us see the day through your eyes.</CardDescription>
        </CardHeader>
        <CardContent>
          <UploadPanel limits={data.limits} onUploaded={() => setGalleryKey((key) => key + 1)} />
        </CardContent>
      </Card>

      <Card id="gallery">
        <CardHeader>
          <CardTitle>Gallery</CardTitle>
        </CardHeader>
        <CardContent>
          <Gallery refreshKey={galleryKey} />
        </CardContent>
      </Card>

      <form method="POST" action="/wedding/leave" className="text-center">
        <input type="hidden" name="_token" value={csrfToken()} />
        <Button type="submit" variant="ghost" size="sm" className="text-muted-foreground">
          <LogOut className="size-4" aria-hidden="true" />
          Not {data.guest.email}? Switch
        </Button>
      </form>
    </div>
  );
}

const root = document.getElementById('wedding');
const bootstrapScript = document.getElementById('wedding-bootstrap');
if (root && bootstrapScript?.textContent) {
  const data = JSON.parse(bootstrapScript.textContent) as WeddingBootstrap;
  createRoot(root).render(<WeddingHub data={data} />);
}
