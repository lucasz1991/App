<?php

return [
    'name' => env('RAILTIME_ASSISTANT_NAME', 'RailTime Assist'),
    'history_limit' => 80,
    'transcript_limit' => 36,
    'max_input_characters' => 4000,
    'max_context_characters' => 60000,

    // Auf diesen Arbeitsflaechen konkurriert ein globales Chatfenster mit der
    // primaeren Kommunikation bzw. mit sensiblen Einstellungsdialogen.
    'excluded_routes' => [
        'chat',
        'admin.settings',
        'profile.show',
    ],

    'chat_limits' => [
        'user_per_minute' => (int) env('RAILTIME_ASSISTANT_USER_PER_MINUTE', 6),
        'user_per_hour' => (int) env('RAILTIME_ASSISTANT_USER_PER_HOUR', 30),
        'user_per_day' => (int) env('RAILTIME_ASSISTANT_USER_PER_DAY', 80),
        'global_per_minute' => (int) env('RAILTIME_ASSISTANT_GLOBAL_PER_MINUTE', 12),
        'global_per_hour' => (int) env('RAILTIME_ASSISTANT_GLOBAL_PER_HOUR', 100),
        'global_per_day' => (int) env('RAILTIME_ASSISTANT_GLOBAL_PER_DAY', 300),
        'provider_concurrency' => (int) env('RAILTIME_ASSISTANT_PROVIDER_CONCURRENCY', 3),
        'lock_seconds' => 660,
    ],

    'openrouter' => [
        'max_completion_tokens' => (int) env('RAILTIME_ASSISTANT_MAX_COMPLETION_TOKENS', 4000),
        'max_response_bytes' => 8 * 1024 * 1024,
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('OPENROUTER_ALLOWED_HOSTS', 'openrouter.ai')),
        ))),
    ],

    'attachments' => [
        'max_count' => 3,
        'max_total_kilobytes' => 15 * 1024,
        'max_text_kilobytes' => 2 * 1024,
        'max_image_kilobytes' => 5 * 1024,
        'max_document_kilobytes' => 10 * 1024,
        'max_extracted_characters' => 20000,
        'max_session_characters' => 30000,
        'max_session_batches' => 3,
        'max_zip_entries' => 2500,
        'max_zip_uncompressed_bytes' => 50 * 1024 * 1024,
        'max_zip_entry_bytes' => 16 * 1024 * 1024,
        'max_zip_compression_ratio' => 100,
        'max_image_dimension' => 2048,
        'max_image_pixels' => 40000000,
        'allowed_extensions' => [
            'txt', 'md', 'csv', 'json',
            'pdf',
            'jpg', 'jpeg', 'png', 'webp',
            'docx', 'xlsx', 'pptx',
        ],
    ],

    'speech' => [
        'enabled' => env('SPEECH_SERVICE_ENABLED', false),
        'url' => env('SPEECH_SERVICE_URL', 'http://127.0.0.1:8092'),
        'client_id' => env('SPEECH_SERVICE_CLIENT_ID', 'railtime'),
        'token' => env('SPEECH_SERVICE_TOKEN'),
        'token_file' => env('SPEECH_SERVICE_TOKEN_FILE'),
        'allow_remote' => env('SPEECH_SERVICE_ALLOW_REMOTE', false),
        'connect_timeout' => (float) env('SPEECH_SERVICE_CONNECT_TIMEOUT', 2),
        'status_timeout' => (float) env('SPEECH_SERVICE_STATUS_TIMEOUT', 5),
        'stt_timeout' => (float) env('SPEECH_SERVICE_STT_TIMEOUT', 300),
        'tts_timeout' => (float) env('SPEECH_SERVICE_TTS_TIMEOUT', 150),
        'language' => env('SPEECH_SERVICE_LANGUAGE', 'de'),
        // 45 Sekunden Browseraudio bleiben deutlich darunter; die kleinere
        // App-Grenze schuetzt PHP-FPM vor Base64-/JSON-Mehrfachkopien.
        'max_audio_kilobytes' => 8192,
        'max_text_characters' => 4000,
    ],
];
