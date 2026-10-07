<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use App\Support\WeddingGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The shared gallery: every ready upload in the order it was taken, with near-identical
 * photos collapsed to their best copy. Guest emails never leave the server;
 * items show the optional display name only.
 */
class WeddingGalleryController extends Controller
{
    private const VARIANTS = ['thumb', 'display', 'original'];

    public function __construct(private readonly FileStorageService $storage) {}

    public function index(Request $request): JsonResponse
    {
        $tokenHash = $this->guestTokenHash($request);

        // In the order things happened (capture time, else upload time), one
        // tile per near-identical photo cluster: its best copy, with the rest
        // counted (and listed by similar()).
        $page = WeddingUpload::query()
            ->ready()
            ->whereNull('duplicate_of_id')
            ->withCount('similar')
            ->orderBy('taken_at')
            ->orderBy('id')
            ->cursorPaginate((int) config('wedding.gallery_page_size'));

        return response()->json([
            'items' => collect($page->items())->map(fn (WeddingUpload $upload): array => $this->item($upload, $tokenHash))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * The other copies collapsed under a gallery photo, best first.
     */
    public function similar(Request $request, WeddingUpload $upload): JsonResponse
    {
        abort_unless($upload->isReady() && $upload->duplicate_of_id === null, 404);
        $tokenHash = $this->guestTokenHash($request);

        $copies = $upload->similar()->get()
            ->sortByDesc(fn (WeddingUpload $copy): array => [$copy->pixels(), $copy->size_bytes ?? 0, -$copy->id]);

        return response()->json([
            'items' => $copies->map(fn (WeddingUpload $copy): array => $this->item($copy, $tokenHash))->values(),
        ]);
    }

    /**
     * Redirect to a short-lived presigned URL for one rendition. A photo
     * without a display derivative (e.g. a HEIC the browser couldn't decode)
     * falls back to its original.
     */
    public function media(WeddingUpload $upload, string $variant): RedirectResponse
    {
        abort_unless($upload->isReady() && in_array($variant, self::VARIANTS, true), 404);

        $disk = (string) config('wedding.disk');
        $ttl = (int) config('wedding.view_url_ttl');

        $url = match ($variant) {
            'thumb' => $upload->thumbnail_key !== null
                ? $this->storage->getSignedViewUrl($disk, $upload->thumbnail_key, $ttl, 'image/jpeg')
                : null,
            'display' => $upload->display_key !== null
                ? $this->storage->getSignedViewUrl($disk, $upload->display_key, $ttl, 'image/jpeg')
                : $this->storage->getSignedViewUrl($disk, $upload->object_key, $ttl, $upload->mime_type),
            default => $this->storage->getSignedDownloadUrl($disk, $upload->object_key, $upload->original_filename, $ttl),
        };

        abort_if($url === null, 404);

        return redirect()->away($url, 302)->header('Cache-Control', 'private, max-age=300');
    }

    private function guestTokenHash(Request $request): string
    {
        /** @var WeddingGuest $guest */
        $guest = $request->attributes->get('weddingGuest');

        return $guest->tokenHash();
    }

    /**
     * One gallery item. No storage I/O: a video's transcode status comes from
     * the cached content id, and the playback proxy resolves it on demand.
     *
     * @return array<string, mixed>
     */
    private function item(WeddingUpload $upload, string $tokenHash): array
    {
        return [
            'ulid' => $upload->ulid,
            'kind' => $upload->kind,
            'guest_name' => $upload->guest_name,
            'mine' => $upload->isOwnedBy($tokenHash),
            'created_at' => $upload->created_at->toIso8601String(),
            'captured_at' => $upload->captured_at?->toIso8601String(),
            'width' => $upload->width,
            'height' => $upload->height,
            'similar_count' => (int) ($upload->similar_count ?? 0),
            'thumb_url' => $upload->thumbnail_key !== null ? $this->variantUrl($upload, 'thumb') : null,
            'display_url' => $upload->kind === WeddingUpload::KIND_PHOTO ? $this->variantUrl($upload, 'display') : null,
            'original_url' => $this->variantUrl($upload, 'original'),
            'master_url' => $upload->isVideo()
                ? route('wedding.hls', ['source' => $upload->ulid, 'path' => 'master.m3u8'], false)
                : null,
            'processing' => $upload->isVideo() && ! $upload->isHlsReady(),
        ];
    }

    private function variantUrl(WeddingUpload $upload, string $variant): string
    {
        return route('wedding.media', ['upload' => $upload->ulid, 'variant' => $variant], false);
    }
}
