@php
    // Bereich bestimmt Sidebar/Topbar-Variante: 'admin' oder 'user'.
    // Ohne expliziten Wert entscheidet Rolle ODER Team (Administrator/Verwaltung).
    $area = $area ?? (auth()->check() && auth()->user()->usesAdminLayout() ? 'admin' : 'user');
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
        <main class="bg-[linear-gradient(to_bottom_right,#f8fafc,#ffffff_45%,#ffe4e6)] dark:bg-[linear-gradient(to_bottom_right,#08080a,#0b090c_45%,#1c0b12)]">
            <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                <div class="min-h-screen page-content px-1 bg-[radial-gradient(72rem_26rem_at_28%_-8rem,rgba(228,0,43,0.07),transparent)] shadow-[inset_0_80px_30px_-10px_rgba(15,23,42,0.05)] dark:bg-[radial-gradient(70rem_28rem_at_28%_-8rem,rgba(228,0,43,0.16),transparent)] dark:shadow-[inset_0_80px_30px_-10px_rgba(0,0,0,0.5)]">
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
            <livewire:messages.message-viewer-modal />
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
 
