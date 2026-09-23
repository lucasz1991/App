<?php

return [
    'queue_connection' => 'dropbox',
    'lock_store' => 'dropbox',
    'max_file_bytes' => 40 * 1024 * 1024,
    'max_uncompressed_bytes' => 256 * 1024 * 1024,
    'scopes' => ['account_info.read', 'files.metadata.read', 'files.content.read', 'files.content.write'],
    'defaults' => [
        'folders' => ['/Disposition'],
        'matrix_path' => '',
        'additional_sources' => [],
        'filename_rule' => 'Aufträge KW {KW} [aktuell].xlsx',
        'auto_discover' => true,
        'watch_history' => true,
        'create_workbooks' => true,
        'create_sheets' => true,
        'append_rows' => true,
        'domains' => ['planning', 'assignments', 'contacts', 'competencies'],
        'debounce_seconds' => 5,
        'check_minutes' => 15,
        'timezone' => 'Europe/Berlin',
        'week_template' => null,
        'employee_template' => null,
    ],
];
