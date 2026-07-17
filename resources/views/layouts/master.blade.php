@php
    // Bereich bestimmt Sidebar/Topbar-Variante: 'admin' oder 'user'.
    // Ohne expliziten Wert wird er aus der Rolle des Nutzers abgeleitet.
    $area = $area ?? (auth()->check() && in_array(auth()->user()->role, ['admin', 'staff'], true) ? 'admin' : 'user');
@endphp
<!DOCTYPE html>
<html lang="de" dir="ltr"
      x-data
      x-bind:class="$store.theme?.dark ? 'dark' : ''">

<head>
    @include('layouts.metahead')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="rt-user-id" content="{{ auth()->id() }}">
        <script>
            window.rtLang = {
                newMessage: @json(__('app.new_message')),
                from: @json(__('app.from')),
            };
        </script>
    @endauth
    <title>@yield('title') | {{ config('app.name', 'RailTime') }}{{ $area === 'admin' ? ' Admin' : '' }}</title>
    {{-- FOUC-Schutz: Theme vor dem ersten Paint anwenden. Die eigentliche
         Verwaltung uebernimmt der Alpine-Persist-Store ($store.theme),
         der auch wire:navigate-Seitenwechsel uebersteht. --}}
    <script>
        if (localStorage.getItem('rt-theme') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <!-- css files -->
    @include('layouts.head-css')
    @vite(['resources/css/app.css'])
    <!-- Styles -->
    @livewireStyles
    @yield('css')
</head>
    <body x-bind:data-mode="$store.theme?.dark ? 'dark' : 'light'" data-mode="light" data-sidebar-size="lg" data-sidebar-collapsible="true" data-sidebar-expanded="false" class="group font-notosans bg-rt-canvas text-rt-text dark:bg-rt-dark-canvas dark:text-rt-dark-text">
        <!-- sidebar -->
        @include('layouts.sidebar', ['area' => $area])
        <!-- topbar -->
        @include('layouts.topbar', ['area' => $area])
        <!-- content -->
        @yield('content')
        <!-- Page Content -->
        @if(isset($slot))
            <main class="bg-gradient-to-br from-rt-canvas via-rt-surface-muted to-rt-sidebar dark:from-rt-dark-canvas dark:via-rt-dark-surface dark:to-rt-dark-sidebar">
                <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                    <div class="min-h-screen page-content px-1" style="box-shadow: inset 0px 80px 30px -10px rgba(0, 0, 0, 0.2);">
                        <div class="container-fluid px-0 md:px-5">
                            {{ $slot }}
                            {{-- Dezente Versionsanzeige --}}
                            <div class="py-4 text-center text-xs text-rt-soft dark:text-rt-dark-soft">
                                {{ config('app.name') }} v{{ config('app.version') }}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        @endif
        @auth
            <livewire:tools.file-pools.file-preview-modal />
        @endauth
        <!-- script -->
        @include('layouts.vendor-scripts')
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
        @livewireScriptConfig
        @yield('js')
    </body>
</html>
 
