<?php

namespace App\Services\Wedding;

use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bridges the app to the out-of-band s3-hls transcoder's output bucket. For
 * every video under the scanned prefix the transcoder writes a mapping at
 * `mappings/<object-key>.json` whose `contentId` points at the content-addressed
 * output tree `by-id/<contentId>/…` (master + per-rung playlists + fMP4
 * segments).
 *
 * Playback goes through a gated proxy (WeddingHlsController): small `.m3u8`
 * manifests are fetched and their child URIs rewritten back through the proxy,
 * while segment requests are 302-redirected to short-lived presigned URLs so R2
 * — not the app — carries the bandwidth. The app never writes to or deletes
 * from the HLS bucket.
 */
class HlsService
{
    /** Re-check interval for a not-yet-transcoded video's mapping. */
    private const RECHECK_AFTER_MINUTES = 2;

    /** Lifetime of presigned segment URLs handed to the browser. */
    private const SEGMENT_URL_TTL_MINUTES = 30;

    private const CEREMONY_CACHE_KEY = 'wedding.ceremony.hls_content_id';

    public function __construct(private readonly FileStorageService $storage) {}

    private function disk(): string
    {
        return (string) config('wedding.hls_disk');
    }

    /**
     * Content id for a guest video, caching it on the row. Returns immediately
     * once known, otherwise reads the mapping at most once per
     * RECHECK_AFTER_MINUTES.
     */
    public function resolveUpload(WeddingUpload $upload): ?string
    {
        if (! $upload->isVideo()) {
            return null;
        }

        if ($upload->isHlsReady()) {
            return $upload->hls_content_id;
        }

        if ($upload->hls_checked_at !== null
            && $upload->hls_checked_at->gt(now()->subMinutes(self::RECHECK_AFTER_MINUTES))) {
            return null;
        }

        $contentId = $this->lookupContentId($upload->object_key);

        $upload->hls_checked_at = now();
        $upload->hls_content_id = $contentId;

        $upload->saveQuietly();

        if ($contentId !== null) {
            $this->hideLaterCopies($contentId);
        }

        return $contentId;
    }

    /**
     * The transcoder's content id hashes the source, so it catches the large
     * videos the browser didn't hash. Keep the earliest ready upload of this
     * content and hide the rest (objects kept, as for any hidden upload) —
     * whichever order the copies happened to resolve in.
     */
    private function hideLaterCopies(string $contentId): void
    {
        $copies = WeddingUpload::query()
            ->where('kind', WeddingUpload::KIND_VIDEO)
            ->where('status', WeddingUpload::STATUS_READY)
            ->where('hls_content_id', $contentId)
            ->orderBy('id')
            ->get();

        $original = $copies->shift();
        foreach ($copies as $copy) {
            $copy->duplicate_of_id = $original?->id;
            $copy->status = WeddingUpload::STATUS_HIDDEN;
            $copy->saveQuietly();
        }
    }

    /**
     * Resolve every ready video still awaiting its transcode, so status and
     * duplicate detection don't wait for someone to press play.
     */
    public function resolvePendingVideos(): int
    {
        $resolved = 0;

        WeddingUpload::query()
            ->ready()
            ->where('kind', WeddingUpload::KIND_VIDEO)
            ->whereNull('hls_content_id')
            ->lazyById()
            ->each(function (WeddingUpload $upload) use (&$resolved): void {
                if ($this->resolveUpload($upload) !== null) {
                    $resolved++;
                }
            });

        return $resolved;
    }

    /**
     * Content id for the ceremony video. A hit is cached for a day; a miss is
     * cached (as an empty string) for RECHECK_AFTER_MINUTES.
     */
    public function resolveCeremony(): ?string
    {
        $sourceKey = config('wedding.ceremony_source_key');
        if (! is_string($sourceKey) || $sourceKey === '') {
            return null;
        }

        $cacheKey = self::CEREMONY_CACHE_KEY.':'.$sourceKey;
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $contentId = $this->lookupContentId($sourceKey);
        Cache::put(
            $cacheKey,
            $contentId ?? '',
            $contentId === null ? now()->addMinutes(self::RECHECK_AFTER_MINUTES) : now()->addDay(),
        );

        return $contentId;
    }

    /**
     * Build a proxied manifest body with every child URI rewritten via the
     * given resolver.
     *
     * @param  callable(string):string  $urlFor  Maps a content-relative path to a proxy URL.
     */
    public function manifest(string $contentId, string $relativePath, callable $urlFor): ?string
    {
        $body = $this->storage->get($this->disk(), $this->objectKey($contentId, $relativePath));
        if ($body === null) {
            return null;
        }

        return $this->rewriteManifest($body, $relativePath, $urlFor);
    }

    /**
     * Short-lived presigned URL for a segment/init object so the browser fetches
     * it straight from R2.
     */
    public function segmentUrl(string $contentId, string $relativePath): ?string
    {
        try {
            return $this->storage->getSignedViewUrl(
                $this->disk(),
                $this->objectKey($contentId, $relativePath),
                self::SEGMENT_URL_TTL_MINUTES,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function isManifestPath(string $relativePath): bool
    {
        return Str::endsWith(strtolower($relativePath), '.m3u8');
    }

    /**
     * Reject anything that could escape the content tree or isn't a plausible HLS
     * artifact name.
     */
    public function isSafeRelativePath(string $relativePath): bool
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\')) {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return preg_match('#^[A-Za-z0-9._/-]+$#', $relativePath) === 1;
    }

    private function lookupContentId(string $sourceKey): ?string
    {
        try {
            $raw = $this->storage->get($this->disk(), 'mappings/'.$sourceKey.'.json');
        } catch (\Throwable $e) {
            // Storage trouble reads as "not transcoded yet" rather than taking
            // the hub page down; it's retried on the next recheck.
            report($e);

            return null;
        }

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $contentId = is_array($decoded) ? ($decoded['contentId'] ?? null) : null;

        // The id becomes a path segment of every object key we read, so accept
        // only the transcoder's own shape (e.g. "sha256:<hex>").
        return is_string($contentId) && preg_match('/^[A-Za-z0-9]+:[A-Za-z0-9]+$/', $contentId) === 1
            ? $contentId
            : null;
    }

    private function objectKey(string $contentId, string $relativePath): string
    {
        return 'by-id/'.$contentId.'/'.$relativePath;
    }

    /**
     * Rewrite a media/master playlist so every child reference points back at the
     * proxy. URI references are resolved relative to the manifest's own directory.
     *
     * @param  callable(string):string  $urlFor
     */
    private function rewriteManifest(string $body, string $manifestRelativePath, callable $urlFor): string
    {
        $baseDir = str_contains($manifestRelativePath, '/')
            ? substr($manifestRelativePath, 0, (int) strrpos($manifestRelativePath, '/'))
            : '';

        $resolve = function (string $childUri) use ($baseDir, $urlFor): string {
            $path = $baseDir === '' ? $childUri : $baseDir.'/'.$childUri;

            return $urlFor($this->normalize($path));
        };

        $lines = preg_split('/\R/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $out[] = $line;

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                // Rewrite URI="..." attributes (EXT-X-MAP, EXT-X-KEY, EXT-X-MEDIA, …).
                $out[] = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    fn (array $m): string => 'URI="'.$resolve($m[1]).'"',
                    $line,
                );

                continue;
            }

            // Bare URI line: a sub-playlist or a segment.
            $out[] = $resolve($trimmed);
        }

        return implode("\n", $out);
    }

    /**
     * Collapse "." / ".." segments in a content-relative path.
     */
    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
