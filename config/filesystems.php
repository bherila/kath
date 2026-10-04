<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (S3-compatible). Private bucket holding original
        // uploads and their client-generated derivatives; the browser reaches
        // objects only through short-lived presigned URLs. Video originals live
        // under the prefix the HLS transcoder scans (see config/wedding.php).
        'r2' => [
            'driver' => 's3',
            'key' => env('KATH_R2_ACCESS_KEY_ID'),
            'secret' => env('KATH_R2_SECRET_ACCESS_KEY'),
            'region' => env('KATH_R2_REGION', 'auto'),
            'bucket' => env('KATH_R2_BUCKET'),
            'url' => env('KATH_R2_URL'),
            'endpoint' => env('KATH_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('KATH_R2_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // HLS output written by the out-of-band s3-hls transcoder: content-
        // addressed `by-id/<contentId>/…` trees plus `mappings/<sourceKey>.json`.
        // The app only reads it; credentials default to the r2 disk's.
        'r2_hls' => [
            'driver' => 's3',
            'key' => env('KATH_R2_HLS_ACCESS_KEY_ID', env('KATH_R2_ACCESS_KEY_ID')),
            'secret' => env('KATH_R2_HLS_SECRET_ACCESS_KEY', env('KATH_R2_SECRET_ACCESS_KEY')),
            'region' => env('KATH_R2_REGION', 'auto'),
            'bucket' => env('KATH_R2_HLS_BUCKET'),
            'endpoint' => env('KATH_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('KATH_R2_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
