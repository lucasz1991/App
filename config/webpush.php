<?php

use App\Models\PushSubscription;

return [
    'enabled' => env('WEBPUSH_ENABLED', false),
    'test_enabled' => env('WEBPUSH_TEST_ENABLED', false),
    'queue' => env('WEBPUSH_QUEUE', 'webpush'),
    'default_ttl' => (int) env('WEBPUSH_DEFAULT_TTL', 3600),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'allowed_endpoint_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env(
            'WEBPUSH_ALLOWED_ENDPOINT_HOSTS',
            'fcm.googleapis.com,*.push.services.mozilla.com,*.push.apple.com,*.notify.windows.com,*.wns.windows.com,*.push.samsung.com'
        ))
    ))),
    'client_options' => [
        'allow_redirects' => false,
        'connect_timeout' => 10,
        'timeout' => 30,
    ],
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),
];
