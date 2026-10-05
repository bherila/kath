<?php

namespace App\Services\Wedding;

use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use App\Support\PerceptualHash;
use App\Support\WeddingGuest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Direct-to-R2 guest uploads: create a pending row and hand back presigned PUT
 * URLs (original plus optional client-made display/thumbnail JPEGs), drive
 * multipart sessions for large videos, and confirm the objects landed.
 *
 * Duplicate avoidance is shared-gallery wide: a SHA-256 computed in the
 * browser blocks a byte-identical file already shared by anyone, and a
 * perceptual hash flags (never blocks) near-identical photos.
 */
class WeddingUploadService
{
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/avif' => 'avif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/3gpp' => '3gp',
        'video/x-m4v' => 'm4v',
    ];

    private const DERIVATIVE_MIME = 'image/jpeg';

    public function __construct(private readonly FileStorageService $storage) {}

    private function disk(): string
    {
        return (string) config('wedding.disk');
    }

    /**
     * The upload that already holds this exact file: a ready row, or another
     * guest's recent pending row. Null when the file is new.
     */
    public function findExactDuplicate(string $fileHash, WeddingGuest $guest): ?WeddingUpload
    {
        return WeddingUpload::query()
            ->where('file_hash', $fileHash)
            ->where(function ($query) use ($guest): void {
                $query->where('status', WeddingUpload::STATUS_READY)
                    ->orWhere(function ($query) use ($guest): void {
                        $query->where('status', WeddingUpload::STATUS_PENDING)
                            ->where('guest_token_hash', '!=', $guest->tokenHash())
                            ->where('created_at', '>=', now()->subHours((int) config('wedding.pending_hold_hours')));
                    });
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Which of the given hashes are already shared (ready) in the gallery.
     *
     * @param  list<string>  $hashes
     * @return list<string>
     */
    public function existingHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return WeddingUpload::query()
            ->ready()
            ->whereIn('file_hash', $hashes)
            ->distinct()
            ->pluck('file_hash')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     upload: WeddingUpload,
     *     upload_url: string,
     *     upload_headers: array<string, string>,
     *     display_upload: ?array{url: string, headers: array<string, string>},
     *     thumbnail_upload: ?array{url: string, headers: array<string, string>},
     * }
     */
    public function createPendingUpload(
        WeddingGuest $guest,
        string $kind,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        ?string $fileHash,
        ?string $perceptualHash,
        ?int $displayBytes,
        ?int $thumbnailBytes,
        string $clientIp,
    ): array {
        // The same guest retrying a file supersedes their own abandoned attempt.
        if ($fileHash !== null) {
            WeddingUpload::query()
                ->where('file_hash', $fileHash)
                ->where('status', WeddingUpload::STATUS_PENDING)
                ->where('guest_token_hash', $guest->tokenHash())
                ->get()
                ->each(fn (WeddingUpload $stale) => $this->discard($stale));
        }

        $ulid = Str::lower((string) Str::ulid());
        $prefix = $kind === WeddingUpload::KIND_VIDEO
            ? config('wedding.video_prefix')
            : config('wedding.photo_prefix');
        $derived = config('wedding.derived_prefix').'/'.$ulid;
        // Only photos get a display copy; videos play via HLS.
        $displayBytes = $kind === WeddingUpload::KIND_PHOTO ? $displayBytes : null;

        $reservedBytes = $sizeBytes + ($displayBytes ?? 0) + ($thumbnailBytes ?? 0);
        $attributes = [
            'ulid' => $ulid,
            'guest_email' => $guest->email,
            'guest_name' => $guest->name,
            'guest_token_hash' => $guest->tokenHash(),
            'uploader_ip' => $clientIp,
            'reserved_bytes' => $reservedBytes,
            'kind' => $kind,
            'status' => WeddingUpload::STATUS_PENDING,
            'object_key' => $prefix.'/'.$ulid.'.'.$this->extensionFor($filename, $mimeType),
            'display_key' => $displayBytes !== null ? $derived.'/display.jpg' : null,
            'thumbnail_key' => $thumbnailBytes !== null ? $derived.'/thumb.jpg' : null,
            'original_filename' => Str::limit($filename, 250, ''),
            'mime_type' => $mimeType,
            'expected_size_bytes' => $sizeBytes,
            'file_hash' => $fileHash,
            'perceptual_hash' => $kind === WeddingUpload::KIND_PHOTO ? $perceptualHash : null,
        ];

        // Check and reserve under one lock so concurrent presigns can't both
        // squeeze under the cap.
        $upload = Cache::lock('wedding.upload-quota', 10)->block(5, function () use ($attributes, $clientIp, $reservedBytes): WeddingUpload {
            $this->assertWithinDailyQuota($clientIp, $reservedBytes);

            return WeddingUpload::query()->create($attributes);
        });

        $ttl = (int) config('wedding.upload_url_ttl');
        // Each URL is bound to its exact byte length. A multipart upload
        // signs per part instead, so this single-PUT URL is never used for it.
        $signed = $this->storage->getSignedUploadUrl($this->disk(), $upload->object_key, $mimeType, $sizeBytes, $ttl);

        return [
            'upload' => $upload,
            'upload_url' => $signed['url'],
            'upload_headers' => $signed['headers'],
            'display_upload' => $upload->display_key !== null
                ? $this->storage->getSignedUploadUrl($this->disk(), $upload->display_key, self::DERIVATIVE_MIME, (int) $displayBytes, $ttl)
                : null,
            'thumbnail_upload' => $upload->thumbnail_key !== null
                ? $this->storage->getSignedUploadUrl($this->disk(), $upload->thumbnail_key, self::DERIVATIVE_MIME, (int) $thumbnailBytes, $ttl)
                : null,
        ];
    }

    /**
     * Bytes reserved today (in the quota's timezone) by every upload still on
     * record. Discarded uploads free their reservation; hidden ones keep it.
     *
     * @throws UploadQuotaExceeded
     */
    private function assertWithinDailyQuota(string $clientIp, int $bytes): void
    {
        $since = now((string) config('wedding.daily_quota.timezone'))->startOfDay()->utc();
        $today = WeddingUpload::query()->where('created_at', '>=', $since);

        if ((clone $today)->sum('reserved_bytes') + $bytes > (int) config('wedding.daily_quota.total_bytes')) {
            throw new UploadQuotaExceeded('We\'ve reached today\'s upload limit for the gallery. Please try again tomorrow.');
        }

        if ((clone $today)->where('uploader_ip', $clientIp)->sum('reserved_bytes') + $bytes > (int) config('wedding.daily_quota.per_ip_bytes')) {
            throw new UploadQuotaExceeded('Your network has reached today\'s upload limit. Please try again tomorrow.');
        }
    }

    public function usesMultipart(WeddingUpload $upload): bool
    {
        return $upload->isVideo()
            && ($upload->expected_size_bytes ?? 0) >= (int) config('wedding.multipart.threshold_bytes');
    }

    /**
     * Confirm the client finished uploading: verify the original exists and is
     * within the limit (the real size, not the declared one), keep derivatives
     * only when they landed within their limits, and mark the row ready.
     * Idempotent for ready rows. Returns false (and discards the row) when the
     * upload can't be verified or lost a race to an identical file.
     */
    public function completeUpload(WeddingUpload $upload): bool
    {
        if ($upload->isReady()) {
            return true;
        }

        $disk = $this->disk();
        $size = $this->storage->getFileSize($disk, $upload->object_key);

        if ($size === null || $size > $this->maxBytesFor($upload->kind)) {
            $this->discard($upload);

            return false;
        }

        foreach (['display_key' => 'display', 'thumbnail_key' => 'thumbnail'] as $column => $limitKey) {
            $key = $upload->{$column};
            if ($key === null) {
                continue;
            }

            // Forget a missing or oversized derivative only once its delete
            // succeeds: the row is the only record of the key, and a failed
            // delete (e.g. during an R2 outage) must stay discoverable.
            $derivativeSize = $this->storage->getFileSize($disk, $key);
            if (($derivativeSize === null || $derivativeSize > (int) config('wedding.max_bytes.'.$limitKey))
                && $this->storage->deleteFile($disk, $key)) {
                $upload->{$column} = null;
            }
        }

        // Two guests can race the same file past the presign check. Serialize
        // completions per hash so the first to complete wins and the later one
        // is discarded.
        $promote = function () use ($upload, $size): bool {
            if ($upload->file_hash !== null && WeddingUpload::query()
                ->ready()
                ->where('file_hash', $upload->file_hash)
                ->whereKeyNot($upload->id)
                ->exists()) {
                return false;
            }

            $upload->size_bytes = $size;
            $upload->status = WeddingUpload::STATUS_READY;
            $upload->multipart_upload_id = null;
            $upload->multipart_part_size_bytes = null;
            $upload->multipart_max_part_number = null;
            $upload->duplicate_of_id = $this->findPerceptualDuplicateId($upload);
            $upload->save();

            return true;
        };

        $promoted = $upload->file_hash === null
            ? $promote()
            : Cache::lock('wedding.complete.'.$upload->file_hash, 30)->block(10, $promote);

        if (! $promoted) {
            $this->discard($upload);
        }

        return $promoted;
    }

    /**
     * @return array{upload_id: string, part_size_bytes: int, max_part_number: int}|null
     */
    public function initMultipartUpload(WeddingUpload $upload): ?array
    {
        if ($upload->isReady() || ! $this->usesMultipart($upload)) {
            return null;
        }

        if ($upload->multipart_upload_id !== null) {
            $this->abortQuietly($upload);
        }

        $partSize = max(5 * 1024 * 1024, (int) config('wedding.multipart.part_size_bytes'));
        $maxPartNumber = (int) ceil(((int) $upload->expected_size_bytes) / $partSize);

        if ($maxPartNumber < 1 || $maxPartNumber > (int) config('wedding.multipart.max_parts')) {
            return null;
        }

        $uploadId = $this->storage->createMultipartUpload($this->disk(), $upload->object_key, $upload->mime_type);

        $upload->multipart_upload_id = $uploadId;
        $upload->multipart_part_size_bytes = $partSize;
        $upload->multipart_max_part_number = $maxPartNumber;
        $upload->save();

        return [
            'upload_id' => $uploadId,
            'part_size_bytes' => $partSize,
            'max_part_number' => $maxPartNumber,
        ];
    }

    /**
     * Part numbers are bounded by the server-tracked count derived from the
     * declared size, and each part by the session part size, so a signed URL
     * can't be used to push more than was declared.
     *
     * @param  list<int>  $partNumbers
     * @param  array<int, int>  $partSizes
     * @return list<array{part_number: int, url: string, headers: array<string, string>}>|null
     */
    public function signedMultipartPartUrls(WeddingUpload $upload, string $uploadId, array $partNumbers, array $partSizes): ?array
    {
        if ($upload->multipart_upload_id !== $uploadId || $upload->isReady()) {
            return null;
        }

        $maxPartNumber = $upload->multipart_max_part_number;
        $partSizeBytes = $upload->multipart_part_size_bytes;
        $unique = collect($partNumbers)->unique()->sort()->values();

        if ($maxPartNumber === null || $partSizeBytes === null || $unique->contains(
            fn (int $n): bool => $n > $maxPartNumber
                || ! isset($partSizes[$n])
                || $partSizes[$n] < 1
                || $partSizes[$n] > $partSizeBytes
        )) {
            return null;
        }

        $ttl = (int) config('wedding.multipart.url_ttl');

        return $unique
            ->map(function (int $n) use ($upload, $uploadId, $partSizes, $ttl): array {
                $signed = $this->storage->getSignedMultipartUploadPartUrl(
                    $this->disk(),
                    $upload->object_key,
                    $uploadId,
                    $n,
                    $partSizes[$n],
                    $ttl,
                );

                return ['part_number' => $n, 'url' => $signed['url'], 'headers' => $signed['headers']];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts
     */
    public function completeMultipartUpload(WeddingUpload $upload, string $uploadId, array $parts): bool
    {
        if ($upload->multipart_upload_id !== $uploadId || $upload->isReady() || $parts === []) {
            return false;
        }

        $maxPartNumber = $upload->multipart_max_part_number;
        if ($maxPartNumber === null || collect($parts)->contains(fn (array $part): bool => $part['part_number'] > $maxPartNumber)) {
            return false;
        }

        $this->storage->completeMultipartUpload($this->disk(), $upload->object_key, $uploadId, $parts);

        return $this->completeUpload($upload);
    }

    public function abortMultipartUpload(WeddingUpload $upload, string $uploadId): bool
    {
        if ($upload->multipart_upload_id !== $uploadId) {
            return false;
        }

        $this->abortQuietly($upload);
        $upload->save();

        return true;
    }

    /**
     * Remove a guest's own upload from the gallery. Objects are kept (hidden,
     * not deleted) so a mistaken removal can be undone by the hosts.
     */
    public function hide(WeddingUpload $upload): void
    {
        if ($upload->status === WeddingUpload::STATUS_PENDING) {
            $this->discard($upload);

            return;
        }

        $upload->status = WeddingUpload::STATUS_HIDDEN;
        $upload->save();
    }

    /**
     * Delete a never-completed upload's objects, then its row. The row holds
     * the only record of the keys, so if any delete fails it is kept as a
     * `deleting` tombstone for prunePending() to retry.
     */
    public function discard(WeddingUpload $upload): bool
    {
        $this->abortQuietly($upload);

        $deleted = true;
        foreach ([$upload->object_key, $upload->display_key, $upload->thumbnail_key] as $key) {
            if ($key === null) {
                continue;
            }

            try {
                $deleted = $this->storage->deleteFile($this->disk(), $key) && $deleted;
            } catch (\Throwable $e) {
                report($e);
                $deleted = false;
            }
        }

        if (! $deleted) {
            $upload->status = WeddingUpload::STATUS_DELETING;
            $upload->save();

            return false;
        }

        $upload->delete();

        return true;
    }

    /**
     * Reap uploads that will never complete: pending rows past the hold
     * window (the browser closed or gave up) and tombstones whose object
     * deletes failed earlier.
     *
     * @return array{discarded: int, retained: int}
     */
    public function prunePending(): array
    {
        $result = ['discarded' => 0, 'retained' => 0];

        WeddingUpload::query()
            ->where(function ($query): void {
                $query->where('status', WeddingUpload::STATUS_DELETING)
                    ->orWhere(function ($query): void {
                        $query->where('status', WeddingUpload::STATUS_PENDING)
                            ->where('created_at', '<', now()->subHours((int) config('wedding.pending_hold_hours')));
                    });
            })
            ->lazyById()
            ->each(function (WeddingUpload $upload) use (&$result): void {
                $this->discard($upload) ? $result['discarded']++ : $result['retained']++;
            });

        return $result;
    }

    private function abortQuietly(WeddingUpload $upload): void
    {
        if ($upload->multipart_upload_id === null) {
            return;
        }

        try {
            $this->storage->abortMultipartUpload($this->disk(), $upload->object_key, $upload->multipart_upload_id);
        } catch (\Throwable) {
            // Already completed/aborted, or expired by the bucket lifecycle rule.
        }

        $upload->multipart_upload_id = null;
        $upload->multipart_part_size_bytes = null;
        $upload->multipart_max_part_number = null;
    }

    private function findPerceptualDuplicateId(WeddingUpload $upload): ?int
    {
        if ($upload->perceptual_hash === null) {
            return null;
        }

        $threshold = (int) config('wedding.perceptual_duplicate_distance');

        return WeddingUpload::query()
            ->ready()
            ->where('kind', WeddingUpload::KIND_PHOTO)
            ->whereNotNull('perceptual_hash')
            ->whereKeyNot($upload->id)
            ->orderBy('id')
            ->get(['id', 'perceptual_hash'])
            ->first(function (WeddingUpload $candidate) use ($upload, $threshold): bool {
                $distance = PerceptualHash::hammingDistance($upload->perceptual_hash, $candidate->perceptual_hash);

                return $distance !== null && $distance <= $threshold;
            })
            ?->id;
    }

    public function maxBytesFor(string $kind): int
    {
        return (int) config('wedding.max_bytes.'.$kind);
    }

    private function extensionFor(string $filename, string $mimeType): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        if ($ext !== '' && strlen($ext) <= 5) {
            return $ext;
        }

        return self::EXTENSION_BY_MIME[strtolower($mimeType)] ?? 'bin';
    }
}
