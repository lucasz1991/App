@props(['title' => 'Kundenportal', 'kicker' => 'Persönlicher Zugang'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>RailTime · Kundenportal</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('rt-brand/rt-logo.svg') }}">
    <script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
    @vite(['resources/css/app.css', 'resources/js/customer-portal.js'])
    @livewireStyles
    @stack('styles')
</head>
<body class="rt-customer-portal-page rt-customer-portal-auth-page font-sans antialiased">
    <a class="rt-customer-portal__skip" href="#portal-auth-form">Zum Formular</a>
    <main class="rt-customer-portal__auth-shell">
        <aside class="rt-customer-portal__auth-aside" aria-hidden="true">
            <div class="rt-customer-portal__auth-brand">
                <span class="rt-customer-portal__brand-mark"><x-customer-portal.icon name="train" /></span>
                <span class="rt-customer-portal__auth-wordmark">RailTime<small>Kundenportal</small></span>
            </div>
            <div>
                <h2 class="rt-customer-portal__auth-aside-title">Ihr Auftrag.<br>Ihr Überblick.</h2>
                <svg class="rt-customer-portal__auth-visual" viewBox="0 0 360 180" fill="none" focusable="false">
                    <g stroke="currentColor" stroke-width="1.25" stroke-linecap="round">
                        <path d="M24 136h108c34 0 48-76 80-76h124M24 148h108c40 0 52-76 80-76h124" />
                        <path d="M24 48h108c34 0 48 64 80 64h124M24 60h108c40 0 52 64 80 64h124" opacity=".45" />
                        <path d="M42 128v28m26-28v28m26-28v28m26-28v28M236 52v28m26-28v28m26-28v28m26-28v28" opacity=".3" />
                        <circle cx="74" cy="142" r="7" /><circle cx="184" cy="92" r="7" /><circle cx="292" cy="66" r="7" />
                    </g>
                    <circle cx="184" cy="92" r="2.5" fill="currentColor" />
                </svg>
                <ol class="rt-customer-portal__auth-route"><li>Anfrage</li><li>Auftrag</li><li>Leistung</li></ol>
            </div>
            <div class="rt-customer-portal__auth-aside-footer"><x-customer-portal.icon name="shield" /><span>Persönlicher Zugang</span></div>
        </aside>
        <section class="rt-customer-portal__auth-panel" aria-labelledby="portal-auth-title">
            <div class="rt-customer-portal__auth-tools">
                <button type="button" class="rt-customer-portal__icon-button" x-on:click="$store.theme?.toggle()" aria-label="Darstellung wechseln" x-bind:aria-label="$store.theme?.dark ? 'Helle Darstellung verwenden' : 'Dunkle Darstellung verwenden'" title="Darstellung wechseln">
                    <span x-show="!$store.theme?.dark"><x-customer-portal.icon name="moon" /></span>
                    <span x-show="$store.theme?.dark" x-cloak><x-customer-portal.icon name="sun" /></span>
                </button>
            </div>
            <div id="portal-auth-form" class="rt-customer-portal__auth-card" tabindex="-1">
                <div class="rt-customer-portal__auth-brand rt-customer-portal__auth-mobile-brand" aria-label="RailTime Kundenportal">
                    <span class="rt-customer-portal__brand-mark"><x-customer-portal.icon name="train" /></span>
                    <span class="rt-customer-portal__auth-wordmark">RailTime<small>Kundenportal</small></span>
                </div>
                <header class="rt-customer-portal__auth-heading">
                    <span class="rt-customer-portal__auth-kicker">{{ $kicker }}</span>
                    <h1 id="portal-auth-title" class="rt-customer-portal__auth-title">{{ $title }}</h1>
                </header>
                @if($errors->any())
                    <div class="rt-customer-portal__auth-errors" role="alert">
                        <strong>Bitte Eingaben prüfen.</strong>
                        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                @if(session('status'))
                    <div role="status" class="rt-customer-portal__auth-state" data-state="success"><x-customer-portal.icon name="check" /><span>{{ session('status') }}</span></div>
                @endif
                {{ $slot }}
                @isset($footer)<footer class="rt-customer-portal__auth-footer">{{ $footer }}</footer>@endisset
            </div>
        </section>
    </main>
    @livewireScriptConfig
</body>
</html>
