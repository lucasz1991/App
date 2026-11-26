@php
    // Bereich bestimmt Sidebar/Topbar-Variante: 'admin' oder 'user'.
    // Ohne expliziten Wert entscheidet ausschliesslich die globale Admin-Rolle.
    $area = $area ?? (auth()->check() && auth()->user()->usesAdminLayout() ? 'admin' : 'user');
    $viewportMode = ($contentMode ?? null) === 'viewport';
    $documentTitle = trim($__env->yieldContent('title')) ?: __('app.dashboard');
    $assistantShouldRender = auth()->check()
        && app(\App\Support\Ai\AssistantAccess::class)->shouldRender(
            auth()->user(),
            request()->route()?->getName(),
        );
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr"
      x-data
      x-bind:class="$store.theme?.dark ? 'dark' : ''">

<head>
    {{-- Meta, Viewport, CSRF, Favicons/PWA und FOUC-Schutz: gemeinsamer
         Dokumentkopf aller Huellen. Font Awesome kommt ueber head-css. --}}
    @include('layouts.document-head', ['documentHead' => ['description' => 'RailTime App']])
    @auth
        <meta name="rt-user-id" content="{{ auth()->id() }}">
        <meta name="rt-live-location-active-url" content="{{ route('chat.live-locations.active') }}">
        <meta name="rt-live-location-tile-url" content="{{ config('chat.live_location.tile_url') }}">
        <meta name="rt-live-location-tile-attribution" content="{{ config('chat.live_location.tile_attribution') }}">
        <meta name="rt-live-location-tile-max-zoom" content="{{ config('chat.live_location.tile_max_zoom') }}">
        <script>
            window.rtLang = {
                newMessage: @json(__('app.new_message')),
                newChatMessage: @json(__('app.new_chat_message')),
                from: @json(__('app.from')),
                missedCall: @json(__('app.calls_missed')),
            };
        </script>
    @endauth
    <title>{{ $documentTitle }} | {{ config('app.name', 'RailTime') }}{{ $area === 'admin' ? ' Admin' : '' }}</title>
    <!-- css files -->
    @include('layouts.head-css')
    {{-- Das globale Modul muss im <head> liegen. Livewire kann Vite-Assets dort
         bei wire:navigate verfolgen und bei einem neuen Build vollstaendig
         neu laden. Im ersetzten <body> wuerde eine neue Hash-Datei sonst eine
         zweite Alpine-/Livewire-Instanz starten. --}}
    {{-- Das Anrufmodul registriert callRoom vor dem Alpine-Start im App-Modul. --}}
    @vite(array_merge(
        ['resources/css/app.css', 'resources/css/shell-redesign.css', 'resources/css/tabs-redesign.css', 'resources/css/chat-redesign.css'],
        auth()->check() ? ['resources/js/calls.js'] : [],
        ['resources/js/app.js'],
    ))
    @livewireStyles
    {{-- Seitenbezogene Styles muessen nach den globalen Bundles kommen, damit
         sie die gemeinsame UI gezielt erweitern koennen. --}}
    @yield('css')
    @stack('styles')
</head>
    <body
        x-bind:data-sidebar-expanded="$store.shell?.desktopSidebarExpanded ? 'true' : 'false'"
        data-sidebar-size="lg"
        data-sidebar-collapsible="false"
        data-sidebar-drawer="true"
        data-sidebar-expanded="false"
        class="group font-sans bg-rt-canvas text-rt-text dark:bg-rt-dark-canvas dark:text-rt-dark-text"
    >
        {{-- Dark Mode haengt allein an html.dark. wire:navigate ersetzt die
             <html>-Attribute durch die serverseitige Fassung (ohne dark) und
             fuehrt dieses Skript beim Body-Swap im selben Takt erneut aus, damit
             zwischen Swap und livewire:navigated nichts hell aufblitzt.
             data-mode bedient nur noch die Legacy-Datei build/css/tailwind.min.css
             (deren Dark-Regeln haengen an data-mode); entfaellt mit ihr. --}}
        <script>
            (function () {
                var dark = localStorage.getItem('rt-theme') === 'true';
                document.documentElement.classList.toggle('dark', dark);
                document.body.dataset.mode = dark ? 'dark' : 'light';
            })();
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
             Sidebar-Offset, Hintergrund, Container) -> identische Seitenstruktur. --}}
        <main id="main-content" tabindex="-1" @class([
            'bg-rt-canvas dark:bg-rt-dark-canvas',
            'rt-viewport-layout' => $viewportMode,
        ])>
            <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                <div @class([
                    'relative isolate min-h-screen page-content rt-app-page-frame bg-rt-canvas shadow-[inset_0_1px_0_rgba(255,255,255,0.82)] dark:bg-rt-dark-canvas dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]',
                    'flex flex-col' => ! $viewportMode,
                ])>
                    @persist('railtime-shell-ambient')
                        <div class="rt-shell-ambient" data-rt-shell-ambient aria-hidden="true">
                            <span class="rt-shell-ambient__mesh"></span>
                            <span class="rt-shell-ambient__pointer" data-rt-shell-pointer></span>
                            <span class="rt-shell-ambient__orb rt-shell-ambient__orb--one" data-rt-shell-orb="one"></span>
                            <span class="rt-shell-ambient__orb rt-shell-ambient__orb--two" data-rt-shell-orb="two"></span>
                            <span class="rt-shell-ambient__rail" data-rt-shell-rail></span>
                        </div>
                    @endpersist
                    <div @class([
                        'relative z-[1] container-fluid rt-page-content w-full max-w-none',
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

                        @endif
                    </div>
                </div>
            </div>
        </main>
        @auth
            <x-ui.floating.launcher-group />
            {{-- Globales Klingel-Overlay fuer eingehende Videoanrufe lebt im
                 persistenten Kommunikationsmodul. --}}
            @persist('railtime-communication-'.auth()->id())
                <div data-communication-persist>
                    <livewire:communication :key="'global-communication-'.auth()->id()" />
                    <livewire:calls.incoming-call-overlay :key="'global-incoming-call-'.auth()->id()" />
                </div>
            @endpersist
            <livewire:settings.session-permissions />
            <livewire:messages.message-viewer-modal />
            <livewire:tools.file-pools.file-preview-modal />
            <x-chat.live-location-preview />
            @if ($assistantShouldRender)
                {{-- Zentral autorisierter RailTime-Assistent; ausgeschlossene
                     Seiten bleiben bewusst frei. --}}
                <livewire:tools.chatbot />
            @endif
        @endauth
        <!-- script -->
        {{-- EIN globales Infomodal fuer alle Info-Buttons dieser Seite. --}}
        <x-ui.info-modal />
        {{-- Eigene, zentral gesteuerte Bestätigung statt browsernativer confirm()-Dialoge. --}}
        <x-ui.confirmation-dialog />

        @include('layouts.vendor-scripts')
        <!-- Scripts -->
        @livewireScriptConfig
        @yield('js')
    </body>
</html>
 
