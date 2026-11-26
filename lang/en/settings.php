<?php

// Texts of the settings page (resources/views/livewire/admin/settings.blade.php)
// that used to live in the view as locale ternaries.
return [
    // Tabs and overview
    'assistant_knowledge_label' => 'Chatbot assistant knowledge pool',
    'assistant_knowledge_description' => 'Manage the default prompt, binding rules, topics and knowledge retrieved on demand.',

    'device_management_label' => 'Device setup',
    'device_management_description' => 'Configure Plesk addresses, device providers, identity domains and safe connection tests.',
    'device_management_items' => ['Subdomains or ports', 'Providers', 'Connection tests'],

    'dropbox_description' => 'Manage Excel sources, synchronization, mappings and conflicts.',
    'dropbox_items' => ['Connection', 'Sources', 'Synchronization'],
];
