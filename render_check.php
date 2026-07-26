<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Blade;

$manifest = json_decode(file_get_contents(__DIR__ . '/public/build/manifest.json'), true);
$appCss = $manifest['resources/css/app.css']['file'];

$u = new \App\Models\User();
$u->id = 1;
$u->role = 'admin';
$u->name = 'Lucas';
$u->email = 'a@b.de';
auth()->setUser($u);

// 1) Echter Seitenkopf (Mitarbeiter-Stil) + Info-Knopf + Drei-Punkte
$header = Blade::render(<<<'BLADE'
<x-ui.page-header
    :title="__('app.employees')"
    count="1.248"
    :help="['title' => 'Mitarbeiter', 'summary' => 'Testtext.', 'points' => ['A', 'B']]"
>
    <x-slot:actions>
        <x-ui.dropdown.anchor-dropdown align="right" width="64">
            <x-slot:trigger><x-ui.dropdown.action-trigger :title="__('app.actions')" /></x-slot:trigger>
            <x-slot:content><x-dropdown-link href="#">Test</x-dropdown-link></x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    </x-slot:actions>
</x-ui.page-header>
BLADE);

// 2) Toolbar-Replikat (Filter-Knopf + schwebendes Panel)
$toolbar = Blade::render(<<<'BLADE'
<x-tables.toolbar :filter-count="1">
    <x-slot:bulk>
        <x-tables.bulk-actions :count="2" select-all="x">
            <x-dropdown-link href="#">Aktion</x-dropdown-link>
        </x-tables.bulk-actions>
    </x-slot:bulk>
    <div class="w-full sm:!w-44"><input class="rt-ui-control h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3" placeholder="Filter A"></div>
    <div class="w-full sm:!w-40"><input class="rt-ui-control h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3" placeholder="Filter B"></div>
</x-tables.toolbar>
BLADE);

// 3) Willkommens-Intro (echt)
$welcome = Blade::render('<x-ui.welcome-intro />');

// 4) Topbar-Kontrollgruppe (Suche + zwei Kontrollknoepfe)
$controls = Blade::render(<<<'BLADE'
<div class="flex items-center justify-end gap-1.5 sm:gap-3" style="width:100%" id="topbar-controls">
    <div class="flex min-w-0 items-center gap-1.5 sm:gap-3">
        <div x-data="{ q: '' }">
            <div
                x-data="{ value: '', expanded: false,
                    open() { this.expanded = true; this.$nextTick(() => this.$refs.input.focus()); } }"
                x-bind:class="{ 'is-expanded': expanded || value.length > 0 }"
                class="rt-expandable-search" data-search-context="topbar" id="tb-search"
            >
                <button x-ref="trigger" type="button" x-on:click="open()" class="rt-expandable-search__trigger" aria-label="Suchen">S</button>
                <input type="text" x-ref="input" x-model="value" class="rt-expandable-search__input" placeholder="Suchen">
            </div>
        </div>
        <x-topbar.control-button class="relative w-9 px-0" id="tb-inbox"><i class="far fa-envelope"></i></x-topbar.control-button>
        <x-topbar.control-button class="w-9 px-0" id="tb-settings"><i class="far fa-cog"></i></x-topbar.control-button>
    </div>
    <button class="h-8 w-8 rounded-full bg-rt-surface-muted" id="tb-profile"></button>
</div>
BLADE);

$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<link rel="stylesheet" href="adminresources/fontawesome6/css/all.min.css">'
    . '<link rel="stylesheet" href="build/css/tailwind.min.css">'
    . '<link rel="stylesheet" href="build/' . $appCss . '">'
    . '<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.15.8/dist/cdn.min.js"></script>'
    . '<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.15.8/dist/cdn.min.js"></script>'
    . '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js"></script>'
    . '</head><body class="bg-rt-canvas p-4 text-rt-text">'
    . '<div class="mx-auto max-w-5xl space-y-6">'
    . '<div id="zone-topbar" class="rounded-xl border border-rt-border bg-rt-topbar p-3">' . $controls . '</div>'
    . '<div id="zone-header">' . $header . '</div>'
    . '<div id="zone-toolbar">' . $toolbar . '</div>'
    . '</div>'
    . $welcome
    . '</body></html>';

file_put_contents(__DIR__ . '/public/_check.html', $html);
echo 'OK' . PHP_EOL;
