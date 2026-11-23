<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
<head>
    {{-- Gemeinsamer Dokumentkopf; Seiten wie das Zeiterfassungs-Terminal
         geben per @section('guest-viewport') einen zoombaren Viewport vor.
         Font Awesome wie in der App (gemeinsamer App-Look der Anmeldung). --}}
    @include('layouts.document-head', ['documentHead' => array_filter([
        'viewport' => trim($__env->yieldContent('guest-viewport')) ?: null,
        'fontAwesome' => true,
    ], fn ($value) => $value !== null)])
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Auth-Stylesheet bleibt statisch (Vite-Manifest ohne eigenen Einstieg);
         Space Mono liegt lokal unter rt-brand/fonts statt am Google-CDN. --}}
    <link rel="stylesheet" href="{{ asset('rt-brand/rt-auth.css') }}?v=20261123-local-fonts">
    @livewireStyles
    @yield('css')
    @stack('styles')
</head>
<body class="font-sans bg-rt-canvas text-rt-text antialiased dark:bg-rt-dark-canvas dark:text-rt-dark-text">
    <main>
        {{ $slot }}
    </main>
    {{-- Eigene, zentral gesteuerte Bestätigung statt browsernativer confirm()-Dialoge (auch für wire:confirm). --}}
    <x-ui.confirmation-dialog />
    @livewireScriptConfig
</body>
</html>
