<?php

namespace App\Services;

use App\Support\LengthBoundS3Signature;
use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Stateless wrapper around S3-compatible (R2) filesystem disks providing
 * presigned upload URLs, signed view/download URLs, and object operations.
 * The disk is passed per call so the service can be injected and mocked.
 * Bucket names come from the disk config, never from code.
 */
class FileStorageService
{
    protected function storage(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }

    protected function bucket(string $disk): string
    {
        $bucket = config("filesystems.disks.{$disk}.bucket");

        if (empty($bucket)) {
            throw new \RuntimeException("Bucket is not configured for disk [{$disk}].");
        }

        return $bucket;
    }

    protected function s3Client(string $disk): S3Client
    {
        $storage = $this->storage($disk);

        if (! $storage instanceof AwsS3V3Adapter) {
            throw new \RuntimeException("Disk [{$disk}] does not support S3 object operations.");
        }

        return $storage->getClient();
    }

    /**
     * Generate a presigned URL the browser can PUT a file to directly, avoiding
     * server upload size limits. The content type and exact byte length are
     * signed, so storage rejects a body of any other size; the client must
     * send exactly the returned headers (the browser sets Content-Length
     * itself from the body).
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function getSignedUploadUrl(string $disk, string $key, string $contentType, int $contentLength, int $ttlMinutes = 30): array
    {
        return $this->presignWithSignedLength($disk, 'PutObject', [
            'Key' => $key,
            'ContentType' => $contentType,
            'ContentLength' => $contentLength,
        ], $ttlMinutes);
    }

    public function createMultipartUpload(string $disk, string $key, string $contentType): string
    {
        $result = $this->s3Client($disk)->createMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        $uploadId = $result->get('UploadId');
        if (! is_string($uploadId) || $uploadId === '') {
            throw new \RuntimeException('Storage did not return a multipart upload id.');
        }

        return $uploadId;
    }

    /**
     * Presigned URL for one multipart part, bound to that part's exact length.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function getSignedMultipartUploadPartUrl(string $disk, string $key, string $uploadId, int $partNumber, int $contentLength, int $ttlMinutes = 30): array
    {
        return $this->presignWithSignedLength($disk, 'UploadPart', [
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'ContentLength' => $contentLength,
        ], $ttlMinutes);
    }

    /**
     * Presign an upload command with Content-Length (and Content-Type) among
     * the signed headers. Mirrors S3Client::createPresignedRequest, swapping in
     * a signer that doesn't strip those headers.
     *
     * @param  array<string, mixed>  $args
     * @return array{url: string, headers: array<string, string>}
     */
    private function presignWithSignedLength(string $disk, string $operation, array $args, int $ttlMinutes): array
    {
        $client = $this->s3Client($disk);
        $command = $client->getCommand($operation, ['Bucket' => $this->bucket($disk)] + $args);
        $command->getHandlerList()->remove('signer');
        $command->getHandlerList()->remove('s3.checksum');

        $signer = new LengthBoundS3Signature('s3', (string) $client->getRegion());
        $request = $signer->presign(
            \Aws\serialize($command),
            $client->getCredentials()->wait(),
            now()->addMinutes($ttlMinutes),
        );

        // Host and Content-Length are set by the browser, never by script.
        $headers = array_filter(
            $this->flattenHeaders($request->getHeaders()),
            fn (string $name): bool => ! in_array(strtolower($name), ['host', 'content-length'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return ['url' => (string) $request->getUri(), 'headers' => $headers];
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts
     */
    public function completeMultipartUpload(string $disk, string $key, string $uploadId, array $parts): bool
    {
        $completed = collect($parts)
            ->sortBy('part_number')
            ->map(fn (array $part): array => [
                'PartNumber' => $part['part_number'],
                'ETag' => $part['etag'],
            ])
            ->values()
            ->all();

        $this->s3Client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $completed],
        ]);

        return true;
    }

    public function abortMultipartUpload(string $disk, string $key, string $uploadId): bool
    {
        $this->s3Client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        return true;
    }

    /**
     * Signed URL for viewing an object inline in the browser (img/video src).
     */
    public function getSignedViewUrl(string $disk, string $key, int $ttlMinutes = 60, ?string $contentType = null): string
    {
        $options = [
            'Bucket' => $this->bucket($disk),
            'ResponseContentDisposition' => 'inline',
        ];

        if ($contentType !== null) {
            $options['ResponseContentType'] = $contentType;
        }

        return $this->storage($disk)->temporaryUrl($key, now()->addMinutes($ttlMinutes), $options);
    }

    /**
     * Signed URL that forces a download with the given filename.
     */
    public function getSignedDownloadUrl(string $disk, string $key, string $downloadFilename, int $ttlMinutes = 60): string
    {
        return $this->storage($disk)->temporaryUrl(
            $key,
            now()->addMinutes($ttlMinutes),
            [
                'Bucket' => $this->bucket($disk),
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($downloadFilename).'"',
            ],
        );
    }

    public function get(string $disk, string $key): ?string
    {
        $storage = $this->storage($disk);

        return $storage->exists($key) ? $storage->get($key) : null;
    }

    public function deleteFile(string $disk, string $key): bool
    {
        return $this->storage($disk)->delete($key);
    }

    public function fileExists(string $disk, string $key): bool
    {
        return $this->storage($disk)->exists($key);
    }

    public function getFileSize(string $disk, string $key): ?int
    {
        try {
            return $this->storage($disk)->size($key);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, list<string>|string>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[$name] = is_array($value) ? implode(', ', $value) : $value;
        }

        return $flat;
    }
}
