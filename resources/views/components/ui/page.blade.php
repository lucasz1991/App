@props([
    'title' => null,
    'eyebrow' => null,
    'description' => null,
    'count' => null,
    'backUrl' => null,
    'showBack' => true,
    // Schluessel fuer das Gesehen-Tracking; Standard ist der Routenname.
    'pageKey' => null,
    // Beim ERSTEN Besuch die Seiteninfo automatisch oeffnen. Dashboards
    // schalten das ab, weil dort das Willkommens-Intro laeuft.
    'autoIntro' => true,
    // Livewire-Requests, die laenger als 300 ms dauern, erhalten einen
    // layoutnahen Skeleton. Schnelle Aktionen bleiben dadurch flackerfrei.
    'loadingSkeleton' => true,
    // Abstaende fuer den eigentlichen Seiteninhalt gehoeren auf den inneren
    // Live-Content-Wrapper. So bleiben sie auch mit Skeleton-Overlay wirksam.
    'contentClass' => null,
])

@php
    $routeName = request()->route()?->getName();
    $help = app(\App\Support\PageHelpCatalog::class)->forRoute($routeName, $title);
    // Fallback-Reihenfolge bewusst: Routenname -> Titel -> Pfad. Der Pfad kommt
    // zuletzt, weil er nicht ueberall stabil ist (Livewire-Tests rendern unter
    // einem zufaelligen Endpunkt — ein pfadbasierter Schluessel waere dort bei
    // jedem Render "neu" und das Intro erschiene endlos).
    $resolvedPageKey = 'page:'.($pageKey
        ?: ($routeName
            ?: (filled($title) ? 'title:'.\Illuminate\Support\Str::slug((string) $title) : 'path:'.request()->path())));

    // Jeder Seitenaufruf wird vermerkt (wer hat was schon gesehen); die
    // Rueckmeldung "war der erste" steuert zusaetzlich das Intro.
    $isFirstVisit = \App\Support\PageViews::firstVisit(auth()->user(), $resolvedPageKey);
    $autoIntroPayload = ($autoIntro && $isFirstVisit && filled($help['summary'] ?? null))
        ? array_merge($help, ['intro' => true])
        : null;
@endphp

{{--
    Einheitlicher Seitenrahmen fuer ALLE Admin- und Nutzerseiten.
    Sorgt fuer identisches Padding, denselben Seitenkopf (Titel + Aktionen +
    Info-Knopf) und vermerkt den Seitenaufruf des Nutzers.
--}}
<div {{ $attributes->class('space-y-5 px-0 pb-4 pt-2 lg:py-5') }}>
    @if (! is_null($title) || isset($actions))
        <x-ui.page-header
            :title="$title"
            :eyebrow="$eyebrow"
            :description="$description"
            :count="$count"
            :help="$help"
            :back-url="$backUrl"
            :show-back="$showBack"
        >
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
        </x-ui.page-header>
    @endif

    @if ($autoIntroPayload)
        {{-- Erster Besuch dieser Seite: die Seiteninfo oeffnet sich von selbst
             im globalen Infomodal (mit "Los geht's" zum Ueberspringen). --}}
        <div
            x-data
            x-init="window.setTimeout(() => $dispatch('rt-info:open', {{ \Illuminate\Support\Js::from($autoIntroPayload) }}), 650)"
            data-rt-intro-auto="{{ $resolvedPageKey }}"
            aria-hidden="true"
        ></div>
    @endif

    <div class="relative min-h-32" data-page-loading-region>
        @if ($loadingSkeleton)
            <div
                wire:loading.delay.long
                class="rt-page-skeleton-overlay absolute inset-0 z-20"
                data-page-loading-skeleton
            >
                <x-ui.loading.skeleton variant="page" :rows="4" />
            </div>
        @endif

        <div
            @if ($loadingSkeleton) wire:loading.class.delay.long="rt-page-live-content--loading" @endif
            @if ($loadingSkeleton) wire:loading.attr.delay.long="inert" @endif
            @class([$contentClass])
            data-page-live-content
        >
            {{ $slot }}
        </div>
    </div>
</div>
