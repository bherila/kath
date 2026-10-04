<?php

namespace Tests\Feature\Wedding;

use App\Services\FileStorageService;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic presigning for tests (real presigning needs an S3 client).
 * Completing a multipart upload writes the object to the fake disk.
 */
class FakeFileStorageService extends FileStorageService
{
    public function getSignedUploadUrl(string $disk, string $key, string $contentType, int $ttlMinutes = 30): array
    {
        return ['url' => "https://r2.example.test/{$disk}/{$key}?put", 'headers' => ['Content-Type' => $contentType]];
    }

    public function createMultipartUpload(string $disk, string $key, string $contentType): string
    {
        return 'mp-'.md5($key);
    }

    public function getSignedMultipartUploadPartUrl(string $disk, string $key, string $uploadId, int $partNumber, int $contentLength, int $ttlMinutes = 30): array
    {
        return ['url' => "https://r2.example.test/{$disk}/{$key}?part={$partNumber}", 'headers' => []];
    }

    public function completeMultipartUpload(string $disk, string $key, string $uploadId, array $parts): bool
    {
        Storage::disk($disk)->put($key, str_repeat('v', 10));

        return true;
    }

    public function abortMultipartUpload(string $disk, string $key, string $uploadId): bool
    {
        return true;
    }

    public function getSignedViewUrl(string $disk, string $key, int $ttlMinutes = 60, ?string $contentType = null): string
    {
        return "https://r2.example.test/{$disk}/{$key}?view";
    }

    public function getSignedDownloadUrl(string $disk, string $key, string $downloadFilename, int $ttlMinutes = 60): string
    {
        return "https://r2.example.test/{$disk}/{$key}?download";
    }
}
