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
    <body x-bind:data-mode="$store.theme?.dark ? 'dark' : 'light'" data-mode="light" data-sidebar-size="lg" data-sidebar-collapsible="true" data-sidebar-expanded="false" class="group font-sans bg-rt-canvas text-rt-text dark:bg-rt-dark-canvas dark:text-rt-dark-text">
        <!-- sidebar -->
        @include('layouts.sidebar', ['area' => $area])
        <!-- topbar -->
        @include('layouts.topbar', ['area' => $area])
        <!-- content -->
        {{-- Einheitliche Content-Huelle fuer BEIDE Render-Wege:
             @extends-Seiten liefern @section('content'), Livewire-Seiten via
             ->layout() liefern $slot. Beide landen im selben Wrapper (gleicher
             Sidebar-Offset, Gradient, Container) -> identische Seitenstruktur. --}}
        <main class="bg-gradient-to-br from-rt-canvas via-rt-surface-muted to-rt-sidebar dark:from-rt-dark-canvas dark:via-rt-dark-surface dark:to-rt-dark-sidebar">
            <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                <div class="min-h-screen page-content px-1 bg-[radial-gradient(70rem_22rem_at_50%_-8rem,rgba(228,0,43,0.05),transparent)] shadow-[inset_0_80px_30px_-10px_rgba(15,23,42,0.08)] dark:bg-[radial-gradient(70rem_22rem_at_50%_-8rem,rgba(228,0,43,0.08),transparent)] dark:shadow-[inset_0_80px_30px_-10px_rgba(0,0,0,0.35)]">
                    <div class="container-fluid px-0 md:px-5">
                        @yield('content')
                        {{ $slot ?? '' }}
                        {{-- Dezente Versionsanzeige --}}
                        <div class="mt-6 border-t border-rt-border/60 py-4 text-center text-[11px] font-medium tracking-wide text-rt-soft dark:border-rt-dark-border/60 dark:text-rt-dark-soft">
                            {{ config('app.name') }} v{{ config('app.version') }}
                        </div>
                    </div>
                </div>
            </div>
        </main>
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
 
