<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A photo or video a wedding guest shared from their device.
 *
 * @property int $id
 * @property string $ulid
 * @property string $guest_email
 * @property ?string $guest_name
 * @property string $guest_token_hash
 * @property ?string $uploader_ip
 * @property int $reserved_bytes
 * @property string $kind
 * @property string $status
 * @property string $object_key
 * @property ?string $display_key
 * @property ?string $thumbnail_key
 * @property string $original_filename
 * @property string $mime_type
 * @property ?int $size_bytes
 * @property ?int $expected_size_bytes
 * @property ?string $file_hash
 * @property ?list<string> $perceptual_hashes
 * @property ?int $width
 * @property ?int $height
 * @property ?int $duplicate_of_id For a photo: the best copy of its near-identical
 *                                 cluster, which the gallery shows instead. For a
 *                                 hidden video: the earlier byte-identical copy.
 * @property ?string $multipart_upload_id
 * @property ?int $multipart_part_size_bytes
 * @property ?int $multipart_max_part_number
 * @property ?string $hls_content_id
 * @property ?Carbon $hls_checked_at
 * @property Carbon $created_at
 * @property-read ?int $similar_count
 */
class WeddingUpload extends Model
{
    public const KIND_PHOTO = 'photo';

    public const KIND_VIDEO = 'video';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_HIDDEN = 'hidden';

    /** Discarded, but an object delete failed; the prune command retries. */
    public const STATUS_DELETING = 'deleting';

    protected $fillable = [
        'ulid',
        'guest_email',
        'guest_name',
        'guest_token_hash',
        'uploader_ip',
        'reserved_bytes',
        'kind',
        'status',
        'object_key',
        'display_key',
        'thumbnail_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'expected_size_bytes',
        'file_hash',
        'perceptual_hashes',
        'width',
        'height',
    ];

    protected $hidden = [
        'guest_email',
        'guest_token_hash',
        'uploader_ip',
        'multipart_upload_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'perceptual_hashes' => 'array',
            'expected_size_bytes' => 'integer',
            'reserved_bytes' => 'integer',
            'multipart_part_size_bytes' => 'integer',
            'multipart_max_part_number' => 'integer',
            'hls_checked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<WeddingUpload, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * Ready photos this one represents: near-identical copies collapsed under
     * it because it is the best (highest-resolution) of them.
     *
     * @return HasMany<WeddingUpload, $this>
     */
    public function similar(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id')->where('status', self::STATUS_READY);
    }

    /**
     * Pixel count, or 0 when the dimensions are unknown.
     */
    public function pixels(): int
    {
        return ($this->width ?? 0) * ($this->height ?? 0);
    }

    /**
     * @param  Builder<WeddingUpload>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', self::STATUS_READY);
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isHlsReady(): bool
    {
        return $this->hls_content_id !== null;
    }

    public function isOwnedBy(string $guestTokenHash): bool
    {
        return hash_equals($this->guest_token_hash, $guestTokenHash);
    }
}
