<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}</title>
        <script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans bg-rt-canvas text-rt-text antialiased dark:bg-rt-dark-canvas dark:text-rt-dark-text">
        <main>
            {{ $slot }}
        </main>
        @livewireScriptConfig
    </body>
</html>
