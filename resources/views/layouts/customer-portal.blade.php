<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <title>RailTime · Kundenportal</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}">
        <link rel="stylesheet" href="{{ asset('adminresources/fontawesome6/css/all.min.css') }}">
        <script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
        @vite(['resources/css/app.css', 'resources/css/shell-redesign.css', 'resources/js/customer-portal.js'])
        @livewireStyles
        @stack('styles')
    </head>
    <body class="rt-customer-portal-page font-sans antialiased">
        {{ $slot ?? '' }}
        @yield('content')
        @livewireScriptConfig
    </body>
</html>
