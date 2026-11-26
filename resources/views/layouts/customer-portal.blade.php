<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
    <head>
        {{-- Gemeinsamer Dokumentkopf. Kein pwa-head: das Portal hat einen
             eigenen Guard und keine App-Sitzungs-/Push-Metas. --}}
        @include('layouts.document-head', ['documentHead' => [
            'viewport' => 'width=device-width, initial-scale=1, viewport-fit=cover',
            'robots' => 'noindex, nofollow',
            'referrer' => 'no-referrer',
            'pwa' => false,
            'fontAwesome' => true,
        ]])
        <title>RailTime · Kundenportal</title>
        @vite(['resources/css/app.css', 'resources/css/shell.css', 'resources/js/customer-portal.js'])
        @livewireStyles
        @stack('styles')
    </head>
    <body class="rt-customer-portal-page font-sans antialiased">
        {{ $slot ?? '' }}
        @yield('content')
        {{-- Eigene, zentral gesteuerte Bestätigung statt browsernativer confirm()-Dialoge (auch für wire:confirm). --}}
        <x-ui.confirmation-dialog />
        @livewireScriptConfig
    </body>
</html>
