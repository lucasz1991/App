<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('layouts.pwa-head')
    <link rel="icon" type="image/svg+xml" href="{{ asset('rt-brand/rt-logo.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <title>{{ config('app.name') }}</title>
    <script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('rt-brand/rt-auth.css') }}">
    @livewireStyles
    @yield('css')
    @stack('styles')
</head>
<body class="font-sans bg-rt-canvas text-rt-text antialiased dark:bg-rt-dark-canvas dark:text-rt-dark-text">
    <main>
        {{ $slot }}
    </main>
    @livewireScriptConfig
</body>
</html>
