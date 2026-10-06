<?php

return [
    // Outbound delivery remains an explicit deployment decision, independently of customer access.
    'delivery_enabled' => (bool) env('CUSTOMER_PORTAL_DELIVERY_ENABLED', false),
    'invitation_hours' => 48,
    'reset_minutes' => 60,
    'session_minutes' => 120,
    'mail_subject_prefix' => 'RailTime',
];
