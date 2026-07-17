<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Area | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ sidebarOpen: false }" data-sidebar-size="lg" class="group bg-slate-100 text-slate-900 antialiased">
    <header class="fixed inset-x-0 top-0 z-40 border-b border-gray-200 bg-white/95 backdrop-blur">
        <div class="flex h-16 items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-3">
                <button type="button"
                        @click="sidebarOpen = !sidebarOpen"
                        class="inline-flex items-center justify-center rounded-md border border-gray-200 p-2 text-gray-600 lg:hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <a href="{{ route('admin.dashboard') }}" class="text-base font-semibold text-gray-900 sm:text-lg">
                    {{ config('app.name') }} Admin
                </a>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded bg-gray-900 px-3 py-1.5 text-white hover:bg-gray-700">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 z-30 bg-black/30 lg:hidden" x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" x-cloak></div>

    <aside class="fixed bottom-0 left-0 top-16 z-40 w-72 border-r border-gray-200 bg-slate-50 transition-transform duration-200 lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        @include('layouts.admin-sidebar')
    </aside>

    <main class="pt-20 transition-all lg:ml-72">
        <div class="px-3 pb-6 sm:px-5 lg:px-6">
            <div class="rounded-md border border-gray-200 bg-white p-4 sm:p-5">
                {{ $slot }}
            </div>
        </div>
    </main>

    @include('layouts.vendor-scripts')
    @livewireScriptConfig
</body>
</html>
