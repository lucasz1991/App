@php
    $area = $area ?? (auth()->check() && auth()->user()->usesAdminLayout() ? 'admin' : 'user');
    $brandHref = $area === 'admin' ? route('admin.dashboard') : route('dashboard');
@endphp
<nav
    class="rt-ui-topbar rt-shell-topbar fixed left-0 right-0 top-0 z-40 flex items-center border-b border-rt-border/60 bg-rt-topbar/90 text-rt-text shadow-rt-xs backdrop-blur-xl dark:border-rt-dark-border/60 dark:bg-rt-dark-topbar/90 dark:text-rt-dark-text print:hidden"
    data-rt-shell-topbar
    aria-label="{{ app()->getLocale() === 'de' ? 'Kopfnavigation' : 'Header navigation' }}"
>
    <div class="flex justify-between w-full">
        <div class="flex items-center topbar-brand">
            <a
                href="{{ $brandHref }}"
                class="rt-shell-brand-mark flex h-[70px] w-14 shrink-0 items-center justify-center lg:hidden"
                aria-label="RailTime"
            >
                <img
                    class="h-9 w-9 object-contain dark:brightness-0 dark:invert"
                    src="{{ asset('rt-brand/rt-logo.svg') }}"
                    alt="RailTime Logo"
                >
            </a>

            <div
                class="hidden lg:flex navbar-brand items-center justify-between shrink px-3 h-[70px] ltr:border-r rtl:border-l border-rt-border/60 bg-rt-topbar shadow-none dark:border-rt-dark-border/60 dark:bg-rt-dark-topbar">
                <a href="{{ $brandHref }}"
                    class="rt-shell-brand-link flex flex-shrink-0 items-center gap-2 text-lg font-bold leading-[69px]">
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
            <!-- Toggle Button: ausschliesslich Mobile/Tablet, animiert Burger <-> X. -->
            <button type="button"
                class="vertical-menu-btn flex h-[70px] w-14 shrink-0 items-center justify-center border-b border-rt-border/60 px-3 text-rt-muted transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted/70 hover:text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent dark:border-rt-dark-border/60 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted/70 dark:hover:text-rt-dark-text min-[1140px]:hidden"
                id="vertical-menu-btn"
                aria-label="{{ __('app.mobile_navigation') }}"
                aria-controls="app-sidebar"
                aria-expanded="false"
            >
                <span class="rt-menu-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
        <div class="flex min-w-0 flex-1 items-center justify-end gap-2 border-b border-rt-border/60 px-2 dark:border-rt-dark-border/60 sm:px-4 lg:px-6">
            {{-- Reihenfolge der Topbar-Kontrollen: Suche (expandiert nach links,
                 weil die Nachbarn rechts verankert bleiben), dann Posteingang,
                 dann Einstellungen, aussen das Profil. --}}
            <div class="rt-shell-topbar-controls flex min-w-0 items-center gap-1.5 sm:gap-3">
                    @auth
                        <livewire:tools.global-search />

                        {{-- Posteingang (Chats + Nachrichten in einem Dropdown) --}}
                        <livewire:tools.header-inbox />
                    @endauth

                    {{-- Sprache, Darstellung und Toene in einem gemeinsamen Menue. --}}
                    @php
                        $rtLocales = [
                            'de' => ['flag' => 'rt-brand/flags/de.svg', 'label' => __('app.german')],
                            'en' => ['flag' => 'rt-brand/flags/gb.svg', 'label' => __('app.english')],
                        ];
                        $rtLocaleRoutes = collect(array_keys($rtLocales))
                            ->mapWithKeys(fn (string $locale) => [$locale => route('locale.switch', $locale)])
                            ->all();
                    @endphp
                    <x-ui.dropdown.anchor-dropdown
                        align="right"
                        width="72"
                        :offset="10"
                        content-classes="rt-topbar-preferences-panel bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-white"
                        data-topbar-preferences-dropdown="true"
                    >
                        <x-slot:trigger>
                            <x-topbar.control-button
                                data-topbar-preferences-trigger="true"
                                aria-label="{{ __('app.settings') }}"
                                title="{{ __('app.settings') }}"
                                aria-haspopup="menu"
                                x-bind:aria-expanded="open.toString()"
                                class="w-9 px-0 shadow-rt-xs transition-all duration-300 ease-rt-spring active:scale-[0.98]"
                            >
                                <svg data-topbar-preferences-icon="true" xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.592c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.041.147.084.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.245a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.76 6.76 0 010 .255c-.008.378.137.75.43.992l1.003.827c.424.35.534.955.26 1.43l-1.296 2.247a1.125 1.125 0 01-1.37.489l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.592c-.55 0-1.02-.397-1.11-.94l-.213-1.281c-.063-.374-.313-.686-.645-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.075-.124l-1.217.456a1.125 1.125 0 01-1.37-.49l-1.296-2.245a1.125 1.125 0 01.26-1.431l1.003-.827c.293-.241.438-.613.43-.992a6.76 6.76 0 010-.255c.008-.378-.137-.75-.43-.992l-1.003-.827a1.125 1.125 0 01-.26-1.43l1.296-2.247a1.125 1.125 0 011.37-.489l1.217.456c.355.133.75.072 1.076-.124.072-.044.146-.086.22-.128.331-.183.581-.495.644-.869l.213-1.281z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </x-topbar.control-button>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="px-2 pb-2 pt-1">
                                <p class="text-sm font-semibold text-rt-text dark:text-white">{{ __('app.settings') }}</p>
                                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.preferences_description') }}</p>
                            </div>

                            <div class="border-t border-rt-border/70 px-1 pt-2 dark:border-rt-dark-border/70" data-topbar-preference="language" @click.stop>
                                <p class="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.language') }}</p>
                                <x-ui.forms.select
                                    id="topbar-language"
                                    change="const routes = {{ \Illuminate\Support\Js::from($rtLocaleRoutes) }}; if (routes[selected]) window.location.assign(routes[selected]);"
                                    :aria-label="__('app.language')"
                                >
                                    @foreach ($rtLocales as $localeKey => $localeMeta)
                                        <option
                                            value="{{ $localeKey }}"
                                            data-icon="{{ asset($localeMeta['flag']) }}"
                                            @selected(app()->getLocale() === $localeKey)
                                        >{{ $localeMeta['label'] }}</option>
                                    @endforeach
                                </x-ui.forms.select>
                            </div>

                            <div class="mt-2 grid grid-cols-2 gap-2 border-t border-rt-border/70 px-1 pt-2 dark:border-rt-dark-border/70">
                                <div class="flex flex-col items-center gap-2 rounded-xl border border-rt-border bg-rt-surface-muted/60 px-2 py-2.5 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/50">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.appearance') }}</span>
                                    <button
                                        type="button"
                                        role="menuitemcheckbox"
                                        data-topbar-preference="theme"
                                        data-topbar-toggle-track="theme"
                                        x-bind:aria-checked="Boolean($store.theme?.dark).toString()"
                                        aria-label="{{ __('app.appearance') }}"
                                        @click.stop="$store.theme?.toggle()"
                                        class="relative h-7 w-[52px] shrink-0 rounded-full border border-slate-300 bg-slate-300 transition-colors duration-300 ease-rt-spring focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 focus-visible:ring-offset-1 focus-visible:ring-offset-rt-surface dark:border-slate-600 dark:bg-slate-600 dark:focus-visible:ring-offset-rt-dark-surface"
                                        :class="$store.theme?.dark && '!border-rt-red !bg-rt-red'"
                                    >
                                        <span
                                            class="absolute left-1 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-600 shadow-sm transition-transform duration-300 ease-rt-spring"
                                            :class="$store.theme?.dark && 'translate-x-[22px] text-rt-red'"
                                            aria-hidden="true"
                                        >
                                            <svg x-show="!$store.theme?.dark" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5m0 15V21m9-9h-1.5M4.5 12H3m15.364-6.364-1.061 1.061M6.697 17.303l-1.061 1.061m12.728 0-1.061-1.061M6.697 6.697 5.636 5.636M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                                            <svg x-show="$store.theme?.dark" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 0 1 8.646 3.646 9.003 9.003 0 1 0 20.354 15.354Z" /></svg>
                                        </span>
                                    </button>
                                </div>

                                <div class="flex flex-col items-center gap-2 rounded-xl border border-rt-border bg-rt-surface-muted/60 px-2 py-2.5 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/50">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.sound') }}</span>
                                    <button
                                        type="button"
                                        role="menuitemcheckbox"
                                        data-topbar-preference="sound"
                                        data-topbar-toggle-track="sound"
                                        x-bind:aria-checked="Boolean($store.sound?.enabled).toString()"
                                        aria-label="{{ __('app.sound') }}"
                                        @click.stop="$store.sound?.toggle()"
                                        class="relative h-7 w-[52px] shrink-0 rounded-full border border-slate-300 bg-slate-300 transition-colors duration-300 ease-rt-spring focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 focus-visible:ring-offset-1 focus-visible:ring-offset-rt-surface dark:border-slate-600 dark:bg-slate-600 dark:focus-visible:ring-offset-rt-dark-surface"
                                        :class="$store.sound?.enabled && '!border-rt-red !bg-rt-red'"
                                    >
                                        <span
                                            class="absolute left-1 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-600 shadow-sm transition-transform duration-300 ease-rt-spring"
                                            :class="$store.sound?.enabled && 'translate-x-[22px] text-rt-red'"
                                            aria-hidden="true"
                                        >
                                            <svg x-show="$store.sound?.enabled" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
                                            <svg x-show="!$store.sound?.enabled" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m18 9 4 4m0-4-4 4M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
                                        </span>
                                    </button>
                                </div>
                            </div>

                            @auth
                                {{-- Weiterfuehrend: alle Einstellungen im Profil
                                     (Sprache, Darstellung, Toene je Ereignis). --}}
                                <div class="mt-2 border-t border-rt-border/70 px-1 pt-2 dark:border-rt-dark-border/70">
                                    <a
                                        href="{{ route('profile.show') }}"
                                        class="flex min-h-10 items-center justify-between gap-2 rounded-lg px-2.5 text-sm font-medium text-rt-text transition hover:bg-rt-surface-muted hover:text-rt-accent dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                                        data-topbar-more-settings="true"
                                    >
                                        <span class="flex items-center gap-2">
                                            <i class="far fa-sliders-h text-xs text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                                            {{ __('app.more_settings') }}
                                        </span>
                                        <i class="far fa-chevron-right text-[10px] text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                                    </a>
                                </div>
                            @endauth
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>

                    @auth
                        <!-- Settings Dropdown -->
                        <div class="relative ms-1 sm:ms-2" data-topbar-profile>
                            <x-dropdown
                                align="right"
                                width="64"
                                :offset="10"
                                dropdown-classes="rt-topbar-profile-dropdown"
                                content-classes="rt-topbar-profile-panel bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-white"
                            >
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="rt-topbar-profile-trigger group flex min-h-10 items-center gap-2 rounded-full border border-rt-border/80 bg-rt-surface/90 p-1 pe-2 text-sm text-rt-text shadow-rt-xs transition duration-200 ease-rt-spring hover:-translate-y-px hover:border-rt-red/25 hover:shadow-rt-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:border-rt-dark-border dark:bg-rt-dark-surface/90 dark:text-white"
                                        aria-label="{{ __('app.manage_account') }}"
                                        aria-haspopup="menu"
                                    >
                                        <span class="relative">
                                            <img
                                                class="h-8 w-8 rounded-full object-cover ring-1 ring-slate-900/10 dark:ring-white/15"
                                                src="{{ Auth::user()->profile_photo_url }}"
                                                alt="{{ Auth::user()->name }}"
                                            />
                                            <span class="rt-topbar-presence absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-slate-900" aria-hidden="true"></span>
                                        </span>
                                        <span class="hidden min-w-0 text-start xl:block">
                                            <span class="block max-w-36 truncate text-xs font-semibold leading-4">{{ Auth::user()->name }}</span>
                                            <span class="block text-[9px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.profile') }}</span>
                                        </span>
                                        <i class="far fa-chevron-down hidden text-[10px] text-rt-muted transition duration-200 group-aria-expanded:rotate-180 dark:text-white/70 xl:block" aria-hidden="true"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="mb-1 flex items-center gap-3 rounded-xl bg-rt-surface-muted/75 px-3 py-3 dark:bg-rt-dark-surface-muted/70">
                                        <img
                                            class="h-10 w-10 shrink-0 rounded-xl object-cover ring-1 ring-slate-900/10 dark:ring-white/15"
                                            src="{{ Auth::user()->profile_photo_url }}"
                                            alt=""
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ Auth::user()->name }}</p>
                                            <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.manage_account') }}</p>
                                        </div>
                                    </div>
                                    <x-dropdown-link href="{{ route('profile.show') }}">
                                    <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                    </svg>

                                        {{ __('app.profile') }}
                                    </x-dropdown-link>

                                    <div class="my-1 border-t border-rt-border/70 dark:border-rt-dark-border/70"></div>
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
                        <div class="relative ms-1 sm:ms-2">
                            <x-dropdown
                                align="right"
                                width="48"
                                :offset="10"
                                dropdown-classes="rt-topbar-profile-dropdown"
                                content-classes="rt-topbar-profile-panel bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-white"
                            >
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="rt-topbar-profile-trigger flex h-10 w-10 items-center justify-center rounded-full border border-rt-border/80 bg-rt-surface text-rt-muted shadow-rt-xs transition duration-200 ease-rt-spring hover:-translate-y-px hover:border-rt-red/25 hover:text-rt-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-rt-dark-text"
                                        aria-label="{{ $area === 'admin' ? __('app.admin_login') : __('app.login') }}"
                                        aria-haspopup="menu"
                                    >
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
