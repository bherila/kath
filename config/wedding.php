<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Originals and client-generated derivatives go to `disk`; the s3-hls
    | transcoder scans only `video_prefix` there and writes HLS output to
    | `hls_disk`. Photos and derivatives stay outside the video prefix so the
    | transcoder never sees them.
    |
    */

    'disk' => env('WEDDING_DISK', 'r2'),
    'hls_disk' => env('WEDDING_HLS_DISK', 'r2_hls'),

    'video_prefix' => 'videos',
    'photo_prefix' => 'photos',
    'derived_prefix' => 'derived',

    // Object key of the ceremony video on `disk`. Must sit under video_prefix
    // so the transcoder picks it up.
    'ceremony_source_key' => env('WEDDING_CEREMONY_SOURCE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Upload policy
    |--------------------------------------------------------------------------
    */

    'max_bytes' => [
        'photo' => (int) env('WEDDING_MAX_PHOTO_BYTES', 50 * 1024 * 1024),
        'video' => (int) env('WEDDING_MAX_VIDEO_BYTES', 5 * 1024 * 1024 * 1024),
        // Client-generated JPEG derivatives (separate presigned PUTs, so their
        // size is enforced on completion).
        'thumbnail' => 5 * 1024 * 1024,
        'display' => 15 * 1024 * 1024,
    ],

    'mime_types' => [
        'photo' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/avif'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp', 'video/x-m4v'],
    ],

    // Daily byte quotas, reserved at presign time (each presigned PUT is bound
    // to its declared size). They can't be reset by re-entering an email: one
    // is global, the other per client IP — generous, since a whole venue may
    // share one Wi-Fi address. Days run midnight to midnight in `timezone`.
    'daily_quota' => [
        'timezone' => 'America/Los_Angeles',
        'total_bytes' => (int) env('WEDDING_DAILY_QUOTA_BYTES', 100 * 1024 ** 3),
        'per_ip_bytes' => (int) env('WEDDING_DAILY_QUOTA_PER_IP_BYTES', 25 * 1024 ** 3),
    ],

    // A pending (not yet completed) upload holds its file hash against other
    // guests' duplicates only this long, so an abandoned upload can't block a
    // file forever.
    'pending_hold_hours' => 24,

    'upload_url_ttl' => 30,
    'view_url_ttl' => 60,

    'multipart' => [
        'threshold_bytes' => (int) env('WEDDING_MULTIPART_THRESHOLD_BYTES', 100 * 1024 * 1024),
        'part_size_bytes' => (int) env('WEDDING_MULTIPART_PART_SIZE_BYTES', 16 * 1024 * 1024),
        'url_ttl' => 30,
        'max_parts' => 10_000,
    ],

    // Near-identical photos (Hamming distance over the 256-bit blockhash, at
    // the best-matching orientation) are collapsed to their best copy.
    // Run `wedding:recluster-photos` after changing it.
    'perceptual_duplicate_distance' => 10,

    'gallery_page_size' => 30,

];
