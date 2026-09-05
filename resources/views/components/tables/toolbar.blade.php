@props([
    'filterCount' => 0,
    'singleLine' => false,
    'title' => null,
    'resetAction' => null,
    'resetLabel' => null,
    'applyLabel' => null,
    'searchLabel' => null,
    'id' => null,
])

@php
    $filterCount = max(0, (int) $filterCount);
    $singleLine = filter_var($singleLine, FILTER_VALIDATE_BOOL);
    $hasFilters = trim((string) $slot) !== '';
    $hasChips = isset($chips) && trim((string) $chips) !== '';
    $safeResetAction = is_string($resetAction)
        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $resetAction)
            ? $resetAction
            : null;
    $resolvedTitle = filled($title) ? (string) $title : ($hasFilters ? __('app.filters') : __('app.search'));
    $resolvedResetLabel = filled($resetLabel) ? (string) $resetLabel : __('app.reset_filters');
    $resolvedApplyLabel = filled($applyLabel) ? (string) $applyLabel : __('app.apply_filters');
    $resolvedSearchLabel = filled($searchLabel) ? (string) $searchLabel : __('app.search');
    $panelId = filled($id)
        ? (string) $id
        : 'rt-table-filters-'.substr(md5($resolvedTitle.(string) $slot), 0, 10);
@endphp

{{--
    Globaler Premium-Filter fuer Listen und Div-Tabellen.

    Die Filterinstanz bleibt breakpoint-sicher: Alpine mountet entweder den
    Desktop- oder den Mobilpfad. Dadurch existieren niemals doppelte
    Livewire-Modelle oder Select-IDs im aktiven DOM. Filter reagieren weiter
    live; die mobile Hauptaktion schliesst deshalb nur die aufgeklappte Flaeche.
--}}
<section
    {{ $attributes->except('id')->class([
        'rt-table-toolbar mb-3 min-w-0 sm:mb-4',
        'rt-table-toolbar--single-line' => $singleLine,
    ]) }}
    x-data="{
        desktopFilters: window.matchMedia('(min-width: 1024px)').matches,
        filtersOpen: false,
        filterViewport: null,
        filterViewportListener: null,
        init() {
            this.filterViewport = window.matchMedia('(min-width: 1024px)');
            this.desktopFilters = this.filterViewport.matches;
            this.filterViewportListener = (event) => {
                this.desktopFilters = event.matches;
                if (event.matches) this.filtersOpen = false;
            };
            this.filterViewport.addEventListener?.('change', this.filterViewportListener);
        },
        closeFilters(restoreFocus = false) {
            this.filtersOpen = false;
            if (restoreFocus) {
                this.$nextTick(() => this.$refs.filterTrigger?.focus({ preventScroll: true }));
            }
        },
        destroy() {
            this.filterViewport?.removeEventListener?.('change', this.filterViewportListener);
        },
    }"
    x-on:keydown.escape.window="if (filtersOpen) closeFilters(true)"
    data-tables-toolbar
    data-rt-premium-filter
    aria-labelledby="{{ $panelId }}-title"
>
    <header class="rt-table-toolbar__header">
        <div class="rt-table-toolbar__heading">
            <span class="rt-table-toolbar__mark" aria-hidden="true">
                <i class="far fa-sliders-h"></i>
            </span>
            <div class="min-w-0">
                <h2 id="{{ $panelId }}-title" class="rt-table-toolbar__title">{{ $resolvedTitle }}</h2>
                <p class="rt-table-toolbar__hint">{{ __('app.filter_list_hint') }}</p>
            </div>
            <span
                class="rt-table-toolbar__count"
                data-tables-filter-count
                aria-live="polite"
                aria-atomic="true"
            >
                {{ $filterCount }} {{ __('app.active') }}
            </span>
        </div>

        <div class="rt-table-toolbar__actions">
            @isset($bulk)
                <div class="rt-table-toolbar__bulk">
                    {{ $bulk }}
                </div>
            @endisset

            @if ($safeResetAction && $filterCount > 0)
                <button
                    type="button"
                    wire:click="{{ $safeResetAction }}"
                    x-on:click="filtersOpen = false"
                    class="rt-table-toolbar__reset"
                    data-tables-filter-reset
                >
                    <span>{{ $resolvedResetLabel }}</span>
                </button>
            @endif

            @if ($hasFilters)
                <template x-if="! desktopFilters">
                    <button
                        x-ref="filterTrigger"
                        type="button"
                        class="rt-ui-button rt-ui-button-secondary rt-table-filter-trigger"
                        x-on:click="filtersOpen = ! filtersOpen"
                        x-bind:aria-expanded="filtersOpen.toString()"
                        aria-controls="{{ $panelId }}-mobile"
                    >
                        <span>{{ __('app.filters') }}</span>
                        <i class="far fa-chevron-down" x-bind:class="filtersOpen && 'rotate-180'" aria-hidden="true"></i>
                    </button>
                </template>
            @endif
        </div>
    </header>

    <div class="rt-table-toolbar__body">
        @isset($search)
            <div class="rt-table-toolbar__search-field">
                <span class="rt-filter-field__label">{{ $resolvedSearchLabel }}</span>
                <div class="rt-table-toolbar__search min-w-0">
                    {{ $search }}
                </div>
            </div>
        @endisset

        @if ($hasFilters)
            <template x-if="desktopFilters">
                <div
                    class="rt-table-toolbar__filters rt-table-toolbar__filters--desktop"
                    data-tables-filters-desktop
                    aria-label="{{ __('app.filters') }}"
                >
                    {{ $slot }}
                </div>
            </template>

            <template x-if="! desktopFilters">
                <div
                    id="{{ $panelId }}-mobile"
                    x-cloak
                    x-show="filtersOpen"
                    x-transition:enter="rt-filter-panel-enter"
                    x-transition:enter-start="rt-filter-panel-enter-from"
                    x-transition:enter-end="rt-filter-panel-enter-to"
                    x-transition:leave="rt-filter-panel-leave"
                    x-transition:leave-start="rt-filter-panel-leave-from"
                    x-transition:leave-end="rt-filter-panel-leave-to"
                    class="rt-table-toolbar__mobile-panel"
                    data-tables-filters-mobile
                    role="region"
                    aria-label="{{ __('app.filters') }}"
                >
                    <div class="rt-table-toolbar__filters rt-table-toolbar__filters--mobile">
                        {{ $slot }}
                    </div>
                    <button
                        type="button"
                        x-on:click="closeFilters(true)"
                        class="rt-table-toolbar__apply"
                        data-tables-filter-apply
                    >
                        <span>{{ $resolvedApplyLabel }}</span>
                        <i class="far fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </template>
        @endif
    </div>

    @if ($hasChips)
        <div class="rt-table-toolbar__chips" aria-label="{{ __('app.active_filters') }}" data-tables-filter-chips>
            {{ $chips }}
        </div>
    @endif
</section>
