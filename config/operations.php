<?php

return [
    'api_enabled' => env('RAILTIME_OPERATIONS_API_ENABLED', false),
    // Separate integration activation is deliberately required.
    'integrations' => ['email' => false, 'phone' => false, 'portal_import' => false, 'ai' => false],
    'evidence_max_kilobytes' => 10240,
    'display_timezone' => 'Europe/Berlin',
    'clock_start_early_minutes' => 120,
];
