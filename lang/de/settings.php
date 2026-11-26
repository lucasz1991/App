<?php

// Texte der Einstellungsseite (resources/views/livewire/admin/settings.blade.php),
// die bisher per Locale-Ternär direkt in der View standen.
return [
    // Reiter und Übersicht
    'assistant_knowledge_label' => 'Informationspool des Chatbot-Assistenten',
    'assistant_knowledge_description' => 'Default-Prompt, verbindliche Regeln, Themen und gezielt abrufbares Wissen verwalten.',

    'device_management_label' => 'Geräte-Setup',
    'device_management_description' => 'Plesk-Adressen, Geräteprovider, Kontendomänen und sichere Verbindungstests einrichten.',
    'device_management_items' => ['Subdomains oder Ports', 'Provider', 'Funktionstests'],

    'dropbox_description' => 'Excel-Quellen, Synchronisierung, Zuordnungen und Konflikte verwalten.',
    'dropbox_items' => ['Verbindung', 'Quellen', 'Abgleich'],
];
