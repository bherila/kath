<?php

use App\Csp\CloudflareCspPolicy;
use App\Csp\WeddingMediaPreset;
use Spatie\Csp\Presets\Basic;

return [
    'enabled' => true,

    // spatie/laravel-csp v3 builds the header from these presets. (The
    // 'policy' key below is the v2 setting and is not read by v3.)
    'presets' => [
        Basic::class,
        WeddingMediaPreset::class,
    ],

    'policy' => CloudflareCspPolicy::class,
];
