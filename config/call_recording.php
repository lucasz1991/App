<?php

return [
    // Bleibt aus, bis Datenschutztext, Rechtsgrundlage, S3 und Egress abgenommen sind.
    'enabled' => (bool) env('CALL_RECORDING_ENABLED', false),
    'policy_version' => env('CALL_RECORDING_POLICY_VERSION', '2026-08-01'),
    'start_deadline_seconds' => (int) env('CALL_RECORDING_START_DEADLINE', 30),
    'retention_days' => (int) env('CALL_RECORDING_RETENTION_DAYS', 90),
    'queue' => env('CALL_RECORDING_QUEUE', 'calls'),
    'storage_disk' => env('CALL_RECORDING_DISK', 'call_recordings'),

    's3' => [
        'key' => env('CALL_RECORDING_S3_KEY', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('CALL_RECORDING_S3_SECRET', env('AWS_SECRET_ACCESS_KEY')),
        'session_token' => env('CALL_RECORDING_S3_SESSION_TOKEN', env('AWS_SESSION_TOKEN')),
        'region' => env('CALL_RECORDING_S3_REGION', env('AWS_DEFAULT_REGION', 'eu-central-1')),
        'bucket' => env('CALL_RECORDING_S3_BUCKET', env('AWS_BUCKET')),
        'endpoint' => env('CALL_RECORDING_S3_ENDPOINT', env('AWS_ENDPOINT')),
        'use_path_style_endpoint' => (bool) env('CALL_RECORDING_S3_PATH_STYLE', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
    ],
];
