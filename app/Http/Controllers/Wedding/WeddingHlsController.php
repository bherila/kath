<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Models\WeddingUpload;
use App\Services\Wedding\HlsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Gated HLS playback proxy for the ceremony and guest videos. Manifests are
 * served with child URIs rewritten back through this endpoint; segment/init
 * objects are 302-redirected to short-lived presigned R2 URLs.
 */
class WeddingHlsController extends Controller
{
    public function __construct(private readonly HlsService $hls) {}

    public function stream(string $source, string $path = 'master.m3u8'): Response|RedirectResponse|JsonResponse
    {
        if (! $this->hls->isSafeRelativePath($path)) {
            return response()->json(['message' => 'Invalid path.'], 422);
        }

        $contentId = $this->resolve($source);
        if ($contentId === null) {
            return response()->json(['message' => 'This video is still being prepared.'], 404);
        }

        if ($this->hls->isManifestPath($path)) {
            $base = route('wedding.hls', ['source' => $source], false);
            $body = $this->hls->manifest($contentId, $path, fn (string $child): string => $base.'/'.$child);

            if ($body === null) {
                return response()->json(['message' => 'Manifest not found.'], 404);
            }

            return response($body, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'private, max-age=10',
            ]);
        }

        $url = $this->hls->segmentUrl($contentId, $path);
        if ($url === null) {
            return response()->json(['message' => 'Segment not found.'], 404);
        }

        return redirect()->away($url, 302);
    }

    private function resolve(string $source): ?string
    {
        if ($source === 'ceremony') {
            return $this->hls->resolveCeremony();
        }

        $upload = WeddingUpload::query()
            ->ready()
            ->where('ulid', $source)
            ->where('kind', WeddingUpload::KIND_VIDEO)
            ->first();

        return $upload === null ? null : $this->hls->resolveUpload($upload);
    }
}
