@php
    // Bereich bestimmt Sidebar/Topbar-Variante: 'admin' oder 'user'.
    // Ohne expliziten Wert entscheidet ausschliesslich die globale Admin-Rolle.
    $area = $area ?? (auth()->check() && auth()->user()->usesAdminLayout() ? 'admin' : 'user');
    $viewportMode = ($contentMode ?? null) === 'viewport';
    $documentTitle = trim($__env->yieldContent('title')) ?: __('app.dashboard');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr"
      x-data
      x-bind:class="$store.theme?.dark ? 'dark' : ''">

<head>
    @include('layouts.metahead')
    @include('layouts.pwa-head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="rt-user-id" content="{{ auth()->id() }}">
        <script>
            window.rtLang = {
                newMessage: @json(__('app.new_message')),
                newChatMessage: @json(__('app.new_chat_message')),
                from: @json(__('app.from')),
            };
        </script>
    @endauth
    <title>{{ $documentTitle }} | {{ config('app.name', 'RailTime') }}{{ $area === 'admin' ? ' Admin' : '' }}</title>
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
    @vite(['resources/css/app.css', 'resources/css/shell-redesign.css', 'resources/css/tabs-redesign.css', 'resources/css/chat-redesign.css'])
    @livewireStyles
    {{-- Seitenbezogene Styles muessen nach den globalen Bundles kommen, damit
         sie die gemeinsame UI gezielt erweitern koennen. --}}
    @yield('css')
    @stack('styles')
</head>
    <body x-bind:data-mode="$store.theme?.dark ? 'dark' : 'light'" data-mode="light" data-sidebar-size="lg" data-sidebar-collapsible="true" data-sidebar-expanded="false" class="group font-sans bg-rt-canvas text-rt-text dark:bg-rt-dark-canvas dark:text-rt-dark-text">
        <script>
            document.body.dataset.mode = localStorage.getItem('rt-theme') === 'true' ? 'dark' : 'light';
        </script>
        <a
            href="#main-content"
            class="sr-only fixed left-4 top-4 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-md focus:not-sr-only focus:z-[60]"
        >
            {{ app()->getLocale() === 'de' ? 'Zum Inhalt springen' : 'Skip to content' }}
        </a>
        <!-- sidebar -->
        @include('layouts.sidebar', ['area' => $area])
        <!-- topbar -->
        @include('layouts.topbar', ['area' => $area])
        <!-- content -->
        {{-- Einheitliche Content-Huelle fuer BEIDE Render-Wege:
             @extends-Seiten liefern @section('content'), Livewire-Seiten via
             ->layout() liefern $slot. Beide landen im selben Wrapper (gleicher
             Sidebar-Offset, Gradient, Container) -> identische Seitenstruktur. --}}
        <main id="main-content" tabindex="-1" @class([
            'bg-rt-canvas dark:bg-rt-dark-canvas',
            'rt-viewport-layout' => $viewportMode,
        ])>
            <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                <div @class([
                    'min-h-screen page-content bg-[radial-gradient(64rem_28rem_at_92%_-10rem,rgba(228,0,43,0.09),transparent_58%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(242,245,249,0.98))] shadow-[inset_0_1px_0_rgba(255,255,255,0.82)] dark:bg-[radial-gradient(64rem_30rem_at_92%_-10rem,rgba(228,0,43,0.15),transparent_58%),linear-gradient(180deg,rgba(12,20,33,0.98),rgba(8,13,22,1))] dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]',
                    'px-0' => $viewportMode,
                    'px-1' => ! $viewportMode,
                    'flex flex-col' => ! $viewportMode,
                ])>
                    <div @class([
                        'container-fluid w-full max-w-none px-0',
                        'md:px-5' => ! $viewportMode,
                        'flex min-h-full flex-1 flex-col' => ! $viewportMode,
                    ])>
                        @if ($viewportMode)
                            @yield('content')
                            {{ $slot ?? '' }}
                        @else
                            <div class="flex-1">
                                @yield('content')
                                {{ $slot ?? '' }}
                            </div>

                            {{-- Dezente Versionsanzeige als unterer Seitenabschluss --}}
                            <div class="mt-6 border-t border-rt-border/60 py-4 text-center text-[11px] font-medium tracking-wide text-rt-soft dark:border-rt-dark-border/60 dark:text-rt-dark-soft">
                                {{ config('app.name') }} v{{ config('app.version') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </main>
        @auth
            <livewire:messages.message-viewer-modal />
            <livewire:tools.file-pools.file-preview-modal />
        @endauth
        <!-- script -->
        {{-- EIN globales Infomodal fuer alle Info-Buttons dieser Seite. --}}
        <x-ui.info-modal />

        @include('layouts.vendor-scripts')
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
        @livewireScriptConfig
        @yield('js')
    </body>
</html>
 
