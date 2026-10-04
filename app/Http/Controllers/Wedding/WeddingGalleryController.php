<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use App\Services\Wedding\HlsService;
use App\Support\WeddingGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The shared gallery: every ready upload, newest first. Guest emails never
 * leave the server; items show the optional display name only.
 */
class WeddingGalleryController extends Controller
{
    private const VARIANTS = ['thumb', 'display', 'original'];

    public function __construct(
        private readonly FileStorageService $storage,
        private readonly HlsService $hls,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var WeddingGuest $guest */
        $guest = $request->attributes->get('weddingGuest');
        $tokenHash = $guest->tokenHash();

        $page = WeddingUpload::query()
            ->ready()
            ->orderByDesc('id')
            ->cursorPaginate((int) config('wedding.gallery_page_size'));

        $items = collect($page->items())->map(function (WeddingUpload $upload) use ($tokenHash): array {
            $hlsReady = $upload->isVideo() && $this->hls->resolveUpload($upload) !== null;

            return [
                'ulid' => $upload->ulid,
                'kind' => $upload->kind,
                'guest_name' => $upload->guest_name,
                'mine' => $upload->isOwnedBy($tokenHash),
                'created_at' => $upload->created_at->toIso8601String(),
                'thumb_url' => $upload->thumbnail_key !== null ? $this->variantUrl($upload, 'thumb') : null,
                'display_url' => $upload->kind === WeddingUpload::KIND_PHOTO ? $this->variantUrl($upload, 'display') : null,
                'original_url' => $this->variantUrl($upload, 'original'),
                'master_url' => $hlsReady
                    ? route('wedding.hls', ['source' => $upload->ulid, 'path' => 'master.m3u8'], false)
                    : null,
            ];
        });

        return response()->json([
            'items' => $items->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
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

    private function variantUrl(WeddingUpload $upload, string $variant): string
    {
        return route('wedding.media', ['upload' => $upload->ulid, 'variant' => $variant], false);
    }
}
