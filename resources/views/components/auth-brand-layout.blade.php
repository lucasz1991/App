@props(['title' => null, 'description' => null])

{{-- Gemeinsame Auth-Shell fuer Login/Register:
     eine zentrierte Spalte — oben das animierte RailTime-Logo
     (3D-Logo + Animation aus Layout 3 der Website), darunter das Formular. --}}

<link rel="stylesheet" href="{{ asset('rt-brand/rt-auth.css') }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('rt-brand/rt-logo.svg') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@700&display=swap" rel="stylesheet">

<div class="rt-auth">
    {{-- Theme-Umschalter (Hell/Dunkel) — nutzt den globalen Alpine-Theme-Store --}}
    <button type="button" x-data @click="$store.theme?.toggle()"
            class="fixed right-4 top-4 z-50 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/20 backdrop-blur-sm transition-all duration-300 hover:bg-white/20 active:scale-95"
            :aria-label="$store.theme?.dark ? '{{ __('app.toggle_theme') }}' : '{{ __('app.toggle_theme') }}'"
            title="{{ __('app.toggle_theme') }}">
        <svg x-show="!$store.theme?.dark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
        <svg x-show="$store.theme?.dark" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        </svg>
    </button>

    {{-- Markenbuehne: Logo oben --}}
    <div class="rt-auth__brand">
        <div class="rt-auth-brand__logo" id="rt-auth-logo">
            <div class="rt-logo-stage" aria-hidden="true">
                <span class="rt-logo-orbit rt-logo-orbit--outer"></span>
                <span class="rt-logo-orbit rt-logo-orbit--inner"></span>
                <span class="rt-logo-scan"></span>
                <div class="rt-logo-3d"
                     data-rt-logo-3d
                     data-logo-variant="full-spin"
                     data-model-src="{{ asset('rt-brand/models/rt-logo.glb') }}">
                    <canvas aria-hidden="true"></canvas>
                    <img class="rt-logo-3d__fallback" src="{{ asset('rt-brand/rt-logo.svg') }}" alt="" width="512" height="512" aria-hidden="true">
                </div>
            </div>
            <img class="rt-logo-wordmark" src="{{ asset('rt-brand/img/logo-txt.png') }}" alt="RT Rail Time GmbH">
        </div>
    </div>

    {{-- Formular-Karte darunter --}}
    <div class="rt-auth__card">
        <div class="w-full rounded-2xl bg-rt-surface p-6 text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60">
            @if ($title)
                <h1 class="text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $title }}</h1>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $description }}</p>
            @endif

            {{ $slot }}
        </div>
    </div>

    <div class="rt-auth__status" aria-hidden="true">
        <span>RT / 01</span><i></i><span>DE / BUNDESWEIT</span><i></i><span>24 / 7</span>
    </div>

    <div class="mt-3.5 text-center text-[11px] font-medium tracking-wide text-slate-500 dark:text-white/40">
        {{ config('app.name') }} v{{ config('app.version') }}
    </div>
</div>

{{-- Logo-Reveal wie im Layout-3-Hero + 3D-Logo-Engine --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var logo = document.getElementById('rt-auth-logo');
        if (!logo) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            logo.classList.add('is-visible');
            return;
        }
        setTimeout(function () { logo.classList.add('is-visible'); }, 300);
    });
</script>
<script type="module" src="{{ asset('rt-brand/js/logo-3d.js') }}"></script>
