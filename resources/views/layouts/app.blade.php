<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('layouts.pwa-head')
        <title>{{ config('app.name') }}</title>
        <script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
        @vite(['resources/css/app.css', 'resources/css/shell-redesign.css', 'resources/css/tabs-redesign.css', 'resources/css/chat-redesign.css', 'resources/js/app.js'])
        @livewireStyles
        @yield('css')
        @stack('styles')
    </head>
    <body class="font-sans bg-rt-canvas text-rt-text antialiased dark:bg-rt-dark-canvas dark:text-rt-dark-text">
        <main>
            {{ $slot }}
        </main>
        @livewireScriptConfig
        {{-- EIN globales Infomodal fuer alle Info-Buttons dieser Seite. --}}
        <x-ui.info-modal />

    </body>
</html>
