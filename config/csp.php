<?php

use App\Csp\WeddingMediaPreset;
use Spatie\Csp\Presets\Basic;
use Spatie\Csp\Presets\CloudflareWebAnalytics;

return [
    'enabled' => true,

    // spatie/laravel-csp v3 builds the header from these presets. Basic adds
    // a per-request nonce to script-src/style-src: inline <script> tags need
    // @cspNonce or the browser drops them.
    'presets' => [
        Basic::class,
        CloudflareWebAnalytics::class,
        WeddingMediaPreset::class,
    ],
];
