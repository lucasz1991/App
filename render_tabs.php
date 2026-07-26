<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Blade;

$manifest = json_decode(file_get_contents(__DIR__ . '/public/build/manifest.json'), true);
$appCss = $manifest['resources/css/app.css']['file'] ?? 'assets/app.css';

$u = new \App\Models\User();
$u->role = 'admin';
$u->name = 'Admin';
$u->email = 'admin@example.com';
auth()->setUser($u);

// Echte Tabs-Komponente rendern
$tabs = Blade::render(<<<'BLADE'
<x-ui.accordion.tabs
    :tabs="[
        'stammdaten' => ['label' => 'Stammdaten', 'icon' => 'far fa-user'],
        'beschaeftigung' => ['label' => 'Beschäftigung', 'icon' => 'far fa-briefcase'],
        'lohn' => ['label' => 'Lohn & Soziales', 'icon' => 'far fa-coins'],
        'unterlagen' => ['label' => 'Unterlagen', 'icon' => 'far fa-folder'],
    ]"
    persistKey="preview-demo"
>
    <div class="p-4">Panel-Inhalt</div>
</x-ui.accordion.tabs>
BLADE);

$filler = '';
for ($i = 1; $i <= 22; $i++) {
    $filler .= '<div class="mb-3 rounded-xl border border-rt-border bg-rt-surface p-4 text-sm text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted">Inhaltsblock ' . $i . '</div>';
}

$css1 = 'build/css/tailwind.min.css';
$css2 = 'build/' . $appCss;
$faCss = 'adminresources/fontawesome6/css/all.min.css';

// Layout-Kette 1:1 wie master.blade.php: main > .main-content > .page-content.px-1
// > .container-fluid.px-0.md:px-5 > x-ui.page(px-2) > Tabs
$html = '<!doctype html><html data-mode="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<link rel="stylesheet" href="' . $faCss . '">'
    . '<link rel="stylesheet" href="' . $css1 . '">'
    . '<link rel="stylesheet" href="' . $css2 . '">'
    . '<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.3/dist/cdn.min.js"></script>'
    . '<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/persist@3.14.3/dist/cdn.min.js"></script>'
    . '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>'
    . '</head><body data-mode="light" data-sidebar-size="lg" class="group font-sans bg-rt-canvas text-rt-text">'
    // Topbar-Attrappe, exakt 70px hoch und fixed wie im echten Layout
    . '<nav style="position:fixed;top:0;left:0;right:0;height:70px;z-index:40;display:flex;align-items:center;padding:0 16px;'
    . 'background:rgba(244,247,251,.92);backdrop-filter:blur(12px);border-bottom:1px solid #dbe3ee;font-weight:700">TOPBAR (70px)</nav>'
    . '<main>'
    . '<div class="main-content">'
    . '<div class="min-h-screen page-content px-1">'
    . '<div class="container-fluid px-0 md:px-5">'
    . '<div class="space-y-6 px-2 py-6 lg:py-8">'
    . '<h1 class="text-xl font-bold">Seitenkopf</h1>'
    . $tabs
    . '<div class="mt-4">' . $filler . '</div>'
    . '</div></div></div></div></main>'
    . '</body></html>';

file_put_contents(__DIR__ . '/public/_tabs_preview.html', $html);
echo 'WROTE ' . strlen($html) . ' bytes; css=' . $appCss . PHP_EOL;
