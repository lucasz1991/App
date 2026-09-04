@props([
    'filterCount' => 0,
    'singleLine' => false,
])

@php
    $filterCount = (int) $filterCount;
    $singleLine = filter_var($singleLine, FILTER_VALIDATE_BOOL);
    $hasFilters = trim((string) $slot) !== '';
@endphp

{{--
    Einheitliche Tabellen-Werkzeugleiste:
    - Massenaktionen links
    - Suche als direkt erreichbare Primärfunktion
    - mobil weitere Filter im gemeinsamen, viewport-sicheren Anchor-Dropdown
    - ab Desktop dieselben Filter direkt und kompakt in einer Zeile

    Die Filter liegen in zwei x-if-Templates. Alpine mountet immer nur die
    zum Breakpoint passende Instanz. So gibt es nie doppelte Livewire-Modelle
    oder doppelte Select-IDs im aktiven DOM.
--}}
<div
    {{ $attributes->class([
        'rt-table-toolbar mb-3 flex min-w-0 items-center gap-2 sm:mb-4 sm:gap-3',
        'flex-nowrap' => $singleLine,
        'flex-wrap' => ! $singleLine,
    ]) }}
    x-data="{
        desktopFilters: window.matchMedia('(min-width: 1024px)').matches,
        filterViewport: null,
        filterViewportListener: null,
        init() {
            this.filterViewport = window.matchMedia('(min-width: 1024px)');
            this.desktopFilters = this.filterViewport.matches;
            this.filterViewportListener = (event) => {
                this.desktopFilters = event.matches;
            };
            this.filterViewport.addEventListener?.('change', this.filterViewportListener);
        },
        destroy() {
            this.filterViewport?.removeEventListener?.('change', this.filterViewportListener);
        },
    }"
    data-tables-toolbar
>
    @isset($bulk)
        <div class="rt-table-toolbar__bulk shrink-0">
            {{ $bulk }}
        </div>
    @endisset

    @isset($search)
        <div @class([
            'rt-table-toolbar__search min-w-0',
            'flex-1' => $singleLine,
            'order-last w-full sm:order-none sm:min-w-[13rem] sm:flex-1 lg:max-w-sm' => ! $singleLine,
        ])>
            {{ $search }}
        </div>
    @endisset

    @if ($hasFilters)
        <template x-if="desktopFilters">
            <div
                class="rt-table-toolbar__filters ml-auto flex min-w-0 shrink-0 items-center gap-2"
                data-tables-filters-desktop
                aria-label="{{ __('app.filters') }}"
            >
                {{ $slot }}
            </div>
        </template>

        <template x-if="! desktopFilters">
            <div class="rt-table-toolbar__filters ml-auto shrink-0" data-tables-filters-mobile>
                <x-ui.dropdown.anchor-dropdown
                    align="right"
                    width="80"
                    :offset="8"
                    content-role="dialog"
                    content-classes="bg-rt-surface p-3 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                    trigger-classes="inline-flex"
                >
                    <x-slot:trigger>
                        <button
                            type="button"
                            class="rt-ui-button rt-ui-button-secondary rt-table-filter-trigger inline-flex h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3.5 text-sm font-semibold text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
                            aria-label="{{ __('app.filters') }}"
                        >
                            <i class="far fa-sliders-h text-sm text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                            <span @class(['hidden sm:inline' => $singleLine])>{{ __('app.filters') }}</span>
                            @if ($filterCount > 0)
                                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-md bg-rt-red px-1.5 py-0.5 text-xs font-bold leading-none text-white">
                                    {{ $filterCount }}
                                </span>
                            @endif
                            <i
                                @class([
                                    'far fa-chevron-down text-[10px] text-rt-muted transition-transform duration-200 dark:text-rt-dark-muted',
                                    'hidden sm:inline-block' => $singleLine,
                                ])
                                :class="open && 'rotate-180'"
                                aria-hidden="true"
                            ></i>
                        </button>
                    </x-slot:trigger>

                    <x-slot:content>
                        <div class="space-y-2" aria-label="{{ __('app.filters') }}">
                            {{ $slot }}
                        </div>
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
            </div>
        </template>
    @endif
</div>
