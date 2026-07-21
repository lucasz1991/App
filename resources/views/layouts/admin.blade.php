<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data x-bind:class="$store.theme?.dark ? 'dark' : ''">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Area | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ sidebarOpen: false }" x-bind:data-mode="$store.theme?.dark ? 'dark' : 'light'" data-mode="light" data-sidebar-size="lg" class="group bg-rt-canvas text-rt-text antialiased dark:bg-rt-dark-canvas dark:text-rt-dark-text">
    <script>document.body.dataset.mode = localStorage.getItem('rt-theme') === 'true' ? 'dark' : 'light';</script>
    <header class="rt-ui-topbar fixed inset-x-0 top-0 z-40 border-b border-rt-border bg-rt-topbar/95 backdrop-blur dark:border-rt-dark-border dark:bg-rt-dark-topbar/95">
        <div class="flex h-16 items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-3">
                <button type="button"
                        @click="sidebarOpen = !sidebarOpen"
                        class="rt-ui-button rt-ui-button-secondary inline-flex items-center justify-center rounded-md border border-rt-border bg-rt-surface p-2 text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted lg:hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <a href="{{ route('admin.dashboard') }}" class="text-base font-semibold text-rt-text dark:text-rt-dark-text sm:text-lg">
                    {{ config('app.name') }} Admin
                </a>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rt-ui-button rt-ui-button-primary rounded bg-rt-red px-3 py-1.5 text-white hover:bg-rt-red-dark">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 z-30 bg-black/30 lg:hidden" x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" x-cloak></div>

    <aside class="rt-ui-sidebar fixed bottom-0 left-0 top-16 z-40 w-72 border-r border-rt-border bg-rt-sidebar transition-transform duration-200 dark:border-rt-dark-border dark:bg-rt-dark-sidebar lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        @include('layouts.admin-sidebar')
    </aside>

    <main class="pt-20 transition-all lg:ml-72">
        <div class="px-3 pb-6 sm:px-5 lg:px-6">
            <div class="rt-ui-surface rounded-xl border border-rt-border bg-rt-surface p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface sm:p-5">
                {{ $slot }}
            </div>
        </div>
    </main>

    @include('layouts.vendor-scripts')
    @livewireScriptConfig
</body>
</html>
