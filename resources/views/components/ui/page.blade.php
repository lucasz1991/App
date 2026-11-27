@props([
    'title' => null,
    'eyebrow' => null,
    'description' => null,
    'count' => null,
    'backUrl' => null,
    'backLabel' => null,
    'showBack' => true,
    'titleInTopbar' => false,
    'headerVariant' => 'default',
    // Schluessel fuer das Gesehen-Tracking; Standard ist der Routenname.
    'pageKey' => null,
    // Beim ERSTEN Besuch die Seiteninfo automatisch oeffnen. Dashboards
    // schalten das ab, weil dort das Willkommens-Intro laeuft.
    'autoIntro' => true,
    'welcomeIntro' => true,
    // Seiten-Skeletons sind opt-in. Ein ungezieltes wire:loading reagiert
    // sonst auch auf Autosave-Requests und darf Formfelder weder inert setzen
    // noch den nativen mobilen Fokus unterbrechen.
    'loadingSkeleton' => false,
    // Abstaende fuer den eigentlichen Seiteninhalt gehoeren auf den inneren
    // Live-Content-Wrapper. So bleiben sie auch mit Skeleton-Overlay wirksam.
    'contentClass' => null,
    // Eingebettet (Kind-Komponente in einer fremden Seite, z. B. Arbeitsplatz
    // oder Disposition): kein Seitenkopf, kein Intro, kein Gesehen-Tracking
    // und kein Dokumenttitel. Uebrig bleibt ein ops-stack mit optionaler
    // actions-Toolbar. Ersetzt die fruehere Weiche operations.surface/ui.page.
    // Der controls-Slot wandert eingebettet per Teleport in den Kopf der
    // Gastseite ([data-page-header-search]) und braucht genau EIN Wurzelelement.
    'embedded' => false,
    // Browser-Tab-Titel; Standard ist :title. Eine vorher gesetzte
    // @section('title') hat Vorrang (die erste Definition gewinnt).
    'documentTitle' => null,
])

{{--
    eyebrow und description werden bewusst geschluckt: Der Seitenkopf zeigt
    nur Titel, Steuerung, Aktionen und Info-Knopf. Die Props bleiben, damit die
    Werte nicht als HTML-Attribute (title= als Tooltip) am Wrapper landen.
--}}
{{--
    Bewusst ein PHP-if statt der if-Direktive: Livewire setzt vor jede if-Direktive Morph-Marker
    (<!--[if BLOCK]-->). Am Anfang der Komponente laegen sie VOR dem
    Wurzelelement, und Livewire haengte wire:id dann an das erste Kind-Tag
    der Seite statt an deren Wurzel. $embedded aendert sich nach mount nicht.
--}}
@php if ($embedded): @endphp
<section {{ $attributes->class('ops-stack min-w-0') }}>
    @isset($actions)<div class="ops-toolbar justify-end">{{ $actions }}</div>@endisset
    @isset($controls)<x-ui.page-header-actions target="search">{{ $controls }}</x-ui.page-header-actions>@endisset
    {{ $slot }}
</section>
@php else: @endphp
@php
    // Dokumenttitel: layouts.master liest @section('title') (sonst 'Dashboard').
    // Livewire reicht Sections aus der Komponentenansicht an das Layout weiter.
    // Der Inhalt wird roh abgelegt, weil das Layout ihn selbst escaped.
    $resolvedDocumentTitle = trim(strip_tags((string) ($documentTitle ?? $title)));
    if ($resolvedDocumentTitle !== '' && ! $__env->hasSection('title')) {
        $__env->startSection('title', new \Illuminate\Support\HtmlString($resolvedDocumentTitle));
    }

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
    // Admin sowie Mitarbeiter/Gaeste binden ihr Welcome-Intro bereits in
    // ihren Dashboard-Views ein. Die Verwaltung teilt sich die Nutzerroute
    // und erhaelt ihre eng begrenzte Einbindung deshalb im Seitenrahmen.
    $dashboardAudience = auth()->user()?->dashboardAudience();
    $mountManagementWelcome = $welcomeIntro && $routeName === 'dashboard'
        && in_array($dashboardAudience, ['administration', 'management'], true);
    $managementWelcomeInitiallyOpen = $mountManagementWelcome
        ? \App\Support\PageViews::firstVisit(auth()->user(), \App\Support\WelcomeIntroCatalog::TRACKING_KEY)
        : false;
@endphp

{{--
    Einheitlicher Seitenrahmen fuer ALLE Admin- und Nutzerseiten.
    Der aeussere rt-page-content besitzt das Seitenpadding. Hier liegen nur
    der Seitenkopf (Titel + Aktionen + Info-Knopf) und das Gesehen-Tracking.
--}}
<div {{ $attributes->class('rt-page-section space-y-3 p-0') }}>
    @if (! is_null($title) || isset($actions))
        <x-ui.page-header
            :title="$title"
            :eyebrow="$eyebrow"
            :description="$description"
            :count="$count"
            :help="$help"
            :back-url="$backUrl"
            :back-label="$backLabel"
            :show-back="$showBack"
            :title-in-topbar="$titleInTopbar"
            :variant="$headerVariant"
        >
            @isset($controls)
                <x-slot:controls>{{ $controls }}</x-slot:controls>
            @endisset
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

    @if ($mountManagementWelcome)
        <x-ui.welcome-intro :initially-open="$managementWelcomeInitiallyOpen" />
    @endif

    <div class="relative min-h-32" data-page-loading-region>
        @if ($loadingSkeleton)
            <div
                wire:loading.delay.long
                class="rt-page-skeleton-overlay pointer-events-none absolute inset-0 z-20"
                data-page-loading-skeleton
                aria-hidden="true"
            >
                <x-ui.loading.skeleton variant="page" :rows="4" />
            </div>
        @endif

        <div
            @if ($loadingSkeleton) wire:loading.class.delay.long="rt-page-live-content--loading" @endif
            @class([$contentClass])
            data-page-live-content
        >
            {{ $slot }}
        </div>
    </div>
</div>
@php endif; @endphp
