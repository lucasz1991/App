<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Blade;

$manifest = json_decode(file_get_contents(__DIR__ . '/public/build/manifest.json'), true);
$appCss = $manifest['resources/css/app.css']['file'];

$rendered = Blade::render(<<<'BLADE'
<x-ui.page-header
    title="Mitarbeiter"
    eyebrow="Verwaltung"
    :count="42"
    :help="[
        'title' => 'Mitarbeiterverwaltung',
        'summary' => 'Hier verwalten Sie alle Mitarbeiterkonten.',
        'points' => ['Nach Team filtern', 'Mehrfachauswahl nutzen', 'Rechte je Team vergeben'],
    ]"
/>
BLADE);

$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<link rel="stylesheet" href="adminresources/fontawesome6/css/all.min.css">'
    . '<link rel="stylesheet" href="build/css/tailwind.min.css">'
    . '<link rel="stylesheet" href="build/' . $appCss . '">'
    . '<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.15.8/dist/cdn.min.js"></script>'
    . '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js"></script>'
    . '</head><body class="bg-rt-canvas p-8 text-rt-text">'
    . '<div class="mx-auto max-w-4xl">' . $rendered . '</div>'
    . '</body></html>';

file_put_contents(__DIR__ . '/public/_info_preview.html', $html);
echo 'OK' . PHP_EOL;
