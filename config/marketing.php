<?php

return [
    'disk' => env('MARKETING_DISK', 'private'),
    'seed_admin_email' => env('MARKETING_SEED_ADMIN_EMAIL'),

    'formats' => [
        'story' => ['width' => 1080, 'height' => 1920, 'label' => 'Story'],
        'post' => ['width' => 1080, 'height' => 1080, 'label' => 'Social Post'],
        'web' => ['width' => 1200, 'height' => 630, 'label' => 'Web'],
    ],

    'assets' => [
        'max_kilobytes' => 8192,
        'max_pixels' => 40_000_000,
        'editor_limit' => 500,
        'browser_cache_seconds' => 900,
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
    ],

    'renders' => [
        'directory' => 'marketing/renders',
        'cache_version' => 1,
        'node_binary' => env('MARKETING_NODE_BINARY', 'node'),
        'chrome_path' => env('MARKETING_CHROME_PATH'),
        'no_sandbox' => (bool) env('MARKETING_CHROME_NO_SANDBOX', false),
        'timeout_seconds' => (int) env('MARKETING_RENDER_TIMEOUT', 75),
        'script' => base_path('scripts/render-marketing-creative.mjs'),
    ],
];
