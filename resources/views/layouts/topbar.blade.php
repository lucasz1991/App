@php
    $area = $area ?? (auth()->check() && in_array(auth()->user()->role, ['admin', 'staff'], true) ? 'admin' : 'user');
    $brandHref = $area === 'admin' ? route('admin.dashboard') : route('dashboard');
@endphp
<nav class="fixed top-0 left-0 right-0 z-10 flex items-center border-b border-rt-border/60 bg-white/85 text-rt-text shadow-rt-xs backdrop-blur-xl dark:border-rt-dark-border/60 dark:bg-slate-900/85 dark:text-rt-dark-text print:hidden">
    <div class="flex justify-between w-full">
        <div class="flex items-center topbar-brand">
            <div
                class="hidden lg:flex navbar-brand items-center justify-between shrink px-3 h-[70px] ltr:border-r rtl:border-l border-rt-border/60 bg-rt-topbar shadow-none dark:border-rt-dark-border/60 dark:bg-rt-dark-topbar">
                <a href="{{ $brandHref }}"
                    class="flex items-center gap-2 text-lg flex-shrink-0 font-bold leading-[69px]">
                        <img class="inline-block w-10 aspect-square align-middle dark:brightness-0 dark:invert"
                            src="{{ asset('rt-brand/rt-logo.svg') }}"
                            alt="RailTime Logo">
                    <span class="hidden align-middle xl:block">
                        <img
                            class="w-full max-w-44 transition dark:brightness-0 dark:invert"
                            src="{{ asset('rt-brand/img/logo-txt.png') }}"
                            alt="RT Rail Time GmbH">
                    </span>
                </a>
            </div>
            <!-- Toggle Button (relativ zur Sidebar positioniert, sichtbar bei kleineren Bildschirmen) -->
            <button type="button"
                class="border-b border-rt-border/60 text-rt-text transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted/70 dark:border-rt-dark-border/60 dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted/70 group-data-[sidebar-size=sm]:border-rt-border/60 group-data-[sidebar-size=sm]:dark:border-rt-dark-border/60 h-[70px] px-4 rtl:-mr-14 py-1 vertical-menu-btn text-16"
                id="vertical-menu-btn">
                <div class="z-50 text-rt-muted dark:text-rt-dark-muted burger-container group-data-[sidebar-size=lg]:open">
                    <div class="burger-bar bar1"></div>
                    <div class="burger-bar bar2"></div>
                    <div class="burger-bar bar3"></div>
                </div>
            </button>
        </div>
        <div class="flex justify-between w-full items-center ltr:pl-6 rtl:pr-6 ltr:pr-6 rtl:pl-6 border-b border-rt-border/60 dark:border-rt-dark-border/60">
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
                        <x-topbar.control-button
                            @click="open = !open"
                            @click.outside="open = false"
                            aria-label="{{ __('app.language') }}"
                            class="gap-1.5 shadow-rt-xs transition-all duration-300 ease-rt-spring active:scale-[0.98]">
                            <img src="{{ asset($rtCurrentLocale['flag']) }}" alt="" class="h-4 w-6 rounded-sm object-cover">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-rt-muted dark:text-rt-dark-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </x-topbar.control-button>
                        <div x-show="open" x-transition.opacity x-cloak
                             class="absolute right-0 z-50 mt-1.5 w-44 overflow-hidden rounded-xl bg-rt-surface py-1 text-rt-text shadow-rt-md ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                            @foreach ($rtLocales as $localeKey => $localeMeta)
                                <a href="{{ route('locale.switch', $localeKey) }}"
                                   class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors duration-300 ease-rt-spring {{ app()->getLocale() === $localeKey ? 'bg-rt-accent-soft font-medium text-rt-text dark:bg-rt-dark-accent-soft dark:text-rt-dark-text' : 'text-rt-muted hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted' }}">
                                    <img src="{{ asset($localeMeta['flag']) }}" alt="" class="h-4 w-6 rounded-sm object-cover">
                                    {{ $localeMeta['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Hell/Dunkel umschalten --}}
                    <x-topbar.control-button
                        x-data
                        @click="$store.theme?.toggle()"
                        title="{{ __('app.toggle_theme') }}"
                        class="w-9 px-0 shadow-rt-xs transition-all duration-300 ease-rt-spring active:scale-[0.98]">
                        <svg x-show="!$store.theme?.dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                        <svg x-show="$store.theme?.dark" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </x-topbar.control-button>

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
                                        class="flex items-center space-x-2 text-sm border-2 border-transparent rounded-full text-rt-text focus:outline-none focus:border-rt-border dark:text-white dark:focus:border-rt-dark-border transition-all duration-300 ease-rt-spring active:scale-[0.98]">
                                        <img class="h-8 w-8 rounded-full object-cover"
                                            src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                                            <span class="hidden font-medium xl:block">{{ Auth::user()->name }}</span>
                                            <i class="hidden align-bottom mdi mdi-chevron-down xl:block text-rt-muted dark:text-white/80"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="block px-4 py-2 text-xs text-rt-soft dark:text-white/70">
                                        {{ __('app.manage_account') }}
                                    </div>
                                    <x-dropdown-link href="{{ route('profile.show') }}">
                                    <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                    </svg>

                                        {{ __('app.profile') }}
                                    </x-dropdown-link>

                                    <div class="border-t border-rt-border dark:border-rt-dark-border"></div>
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
                                        class="flex items-center justify-center w-10 h-10 bg-rt-surface-muted text-rt-muted rounded-full shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-nav-hover hover:text-rt-accent active:scale-[0.98] dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text">
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
