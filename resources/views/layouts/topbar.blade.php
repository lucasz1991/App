@php
    $area = $area ?? (auth()->check() && in_array(auth()->user()->role, ['admin', 'staff'], true) ? 'admin' : 'user');
    $brandHref = $area === 'admin' ? route('admin.dashboard') : route('dashboard');
@endphp
<nav class="fixed top-0 left-0 right-0 z-10 flex items-center border-b border-slate-200/80 bg-white/95 text-slate-900 shadow-sm backdrop-blur dark:border-slate-800/80 dark:bg-slate-950/95 dark:text-slate-100 print:hidden">
    <div class="flex justify-between w-full">
        <div class="flex items-center topbar-brand">
            <div
                class="hidden lg:flex navbar-brand items-center justify-between shrink px-3 h-[70px] ltr:border-r rtl:border-l border-slate-200 bg-white shadow-none dark:border-slate-800 dark:bg-slate-900">
                <a href="{{ $brandHref }}"
                    class="flex items-center gap-2 text-lg flex-shrink-0 font-bold leading-[69px]">
                        <img class="inline-block w-10 aspect-square align-middle dark:brightness-0 dark:invert"
                            src="{{ asset('rt-brand/rt-logo.svg') }}"
                            alt="RailTime Logo">
                    <span class="hidden align-middle xl:block">
                        <img
                            class="w-full max-w-44 dark:hidden"
                            src="{{ asset('rt-brand/img/logo-txt.png') }}"
                            alt="RT Rail Time GmbH">
                        <img
                            class="hidden w-full max-w-44 brightness-0 invert dark:block"
                            src="{{ asset('rt-brand/img/logo-txt.png') }}"
                            alt="RT Rail Time GmbH">
                    </span>
                </a>
            </div>
            <!-- Toggle Button (relativ zur Sidebar positioniert, sichtbar bei kleineren Bildschirmen) -->
            <button type="button"
                class="border-b border-slate-200 text-slate-800 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800/70 group-data-[sidebar-size=sm]:border-slate-200 group-data-[sidebar-size=sm]:dark:border-slate-700 h-[70px] px-4 rtl:-mr-14 py-1 vertical-menu-btn text-16"
                id="vertical-menu-btn">
                <div class=" z-50 text-gray-600 dark:text-slate-300 burger-container group-data-[sidebar-size=lg]:open">
                    <div class="burger-bar bar1"></div>
                    <div class="burger-bar bar2"></div>
                    <div class="burger-bar bar3"></div>
                </div>
            </button>
        </div>
        <div class="flex justify-between w-full items-center  ltr:pl-6 rtl:pr-6 ltr:pr-6 rtl:pl-6 border-b border-gray-300 dark:border-slate-700">
            <div>


            </div>
            <div class="flex items-center gap-3">
                    {{-- Sprache umschalten (Flaggen-Dropdown) --}}
                    @php
                        $rtLocales = [
                            'de' => ['flag' => 'rt-brand/flags/de.svg', 'label' => __('app.german')],
                            'en' => ['flag' => 'rt-brand/flags/gb.svg', 'label' => __('app.english')],
                        ];
                        $rtCurrentLocale = $rtLocales[app()->getLocale()] ?? $rtLocales['de'];
                    @endphp
                    <div x-data="{ open: false }" class="relative">
                        <button type="button"
                            @click="open = !open"
                            @click.outside="open = false"
                            aria-label="{{ __('app.language') }}"
                            class="flex h-9 items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 shadow-sm transition hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700">
                            <img src="{{ asset($rtCurrentLocale['flag']) }}" alt="" class="h-4 w-6 rounded-sm object-cover">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-gray-500 dark:text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="open" x-transition.opacity x-cloak
                             class="absolute right-0 z-50 mt-1 w-44 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                            @foreach ($rtLocales as $localeKey => $localeMeta)
                                <a href="{{ route('locale.switch', $localeKey) }}"
                                   class="flex items-center gap-2.5 px-3 py-2 text-sm transition {{ app()->getLocale() === $localeKey ? 'bg-gray-50 font-medium text-gray-900 dark:bg-slate-700 dark:text-white' : 'text-gray-700 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-700' }}">
                                    <img src="{{ asset($localeMeta['flag']) }}" alt="" class="h-4 w-6 rounded-sm object-cover">
                                    {{ $localeMeta['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Hell/Dunkel umschalten --}}
                    <button type="button"
                        x-data
                        @click="$store.theme?.toggle()"
                        title="{{ __('app.toggle_theme') }}"
                        class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-4 w-4 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </button>

                    @auth
                        {{-- Posteingang (Umschlag-Dropdown mit Ungelesen-Badge) --}}
                        <livewire:tools.header-inbox />
                    @endauth

                    @auth
                        <!-- Settings Dropdown -->
                        <div class="ms-3 relative">
                            <x-dropdown align="" width="48">
                                <x-slot name="trigger">
                                    <button
                                        class="flex items-center space-x-2 text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 dark:focus:border-slate-600 transition">
                                        <img class="h-8 w-8 rounded-full object-cover"
                                            src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                                            <span class="hidden font-medium xl:block dark:text-slate-200">{{ Auth::user()->name }}</span>
                                            <i class="hidden align-bottom mdi mdi-chevron-down xl:block dark:text-slate-300"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="block px-4 py-2 text-xs text-gray-400 dark:text-slate-400">
                                        {{ __('app.manage_account') }}
                                    </div>
                                    <x-dropdown-link href="{{ route('profile.show') }}">
                                    <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                    </svg>

                                        {{ __('app.profile') }}
                                    </x-dropdown-link>

                                    <div class="border-t border-gray-200 dark:border-slate-700"></div>
                                    <form method="POST" action="{{ route('logout') }}" x-data>
                                        @csrf
                                        <x-dropdown-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                            <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15 9-6 6m0-6 6 6m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                            </svg>

                                            {{ __('app.logout') }}
                                        </x-dropdown-link>
                                    </form>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @else
                        <!-- Guest Dropdown -->
                        <div class="ms-3 relative">
                            <x-dropdown align="" width="48">
                                <x-slot name="trigger">
                                    <button
                                        class="flex items-center justify-center w-10 h-10 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-400 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="w-5 h-5" viewBox="0 0 512 512">
                                            <path
                                            d="M337.711 241.3a16 16 0 0 0-11.461 3.988c-18.739 16.561-43.688 25.682-70.25 25.682s-51.511-9.121-70.25-25.683a16.007 16.007 0 0 0-11.461-3.988c-78.926 4.274-140.752 63.672-140.752 135.224v107.152C33.537 499.293 46.9 512 63.332 512h385.336c16.429 0 29.8-12.707 29.8-28.325V376.523c-.005-71.552-61.831-130.95-140.757-135.223zM446.463 480H65.537V376.523c0-52.739 45.359-96.888 104.351-102.8C193.75 292.63 224.055 302.97 256 302.97s62.25-10.34 86.112-29.245c58.992 5.91 104.351 50.059 104.351 102.8zM256 234.375a117.188 117.188 0 1 0-117.188-117.187A117.32 117.32 0 0 0 256 234.375zM256 32a85.188 85.188 0 1 1-85.188 85.188A85.284 85.284 0 0 1 256 32z"
                                            data-original="#000000"></path>
                                        </svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link href="{{ $area === 'admin' ? route('admin.login') : route('login') }}">
                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14v3m4-6V7a3 3 0 1 1 6 0v4M5 11h10a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z"/>
                                        </svg>

                                        {{ $area === 'admin' ? __('app.admin_login') : __('app.login') }}
                                    </x-dropdown-link>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endauth
                </div>
            </div>
        </div>
    </div>
</nav>
