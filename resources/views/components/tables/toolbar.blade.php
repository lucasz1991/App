@props([
    'filterCount' => 0,
])

@php
    $filterCount = (int) $filterCount;
    $panelId = 'rt-toolbar-filters-' . \Illuminate\Support\Str::random(6);
@endphp

{{--
    Gemeinsame Listen-Toolbar fuer Tabellen.
    - Slot "bulk": Massenaktionen (links). Optional.
    - Default-Slot: Suche, Filter und "pro Seite".
    Desktop (>= lg): alle Controls in einer Reihe, Filter rechts.
    Mobil (< lg): kompakte Zeile aus Massenaktionen + "Filter"-Umschalter; die
    eigentlichen Filter klappen als Modul darunter aus (Alpine x-collapse), damit
    ueber der Liste nicht mehrere Filterzeilen stehen.
--}}
<div
    x-data="{ filtersOpen: false }"
    {{ $attributes->class('flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-3') }}
    data-tables-toolbar
>
    {{-- Kompaktzeile --}}
    <div class="flex items-center gap-2">
        @isset($bulk)
            {{ $bulk }}
        @endisset

        {{-- Filter-Umschalter: nur unter lg sichtbar --}}
        <button
            type="button"
            x-on:click="filtersOpen = ! filtersOpen"
            :aria-expanded="filtersOpen.toString()"
            aria-controls="{{ $panelId }}"
            class="ml-auto flex h-11 shrink-0 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3.5 text-sm font-medium text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] lg:hidden dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
        >
            <svg class="h-[18px] w-[18px] shrink-0 text-rt-muted dark:text-rt-dark-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18M6 12h12M10 19.5h4" />
            </svg>
            <span>{{ __('app.filters') }}</span>
            @if ($filterCount > 0)
                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rt-red px-1.5 py-0.5 text-xs font-bold leading-none text-white">{{ $filterCount }}</span>
            @endif
            <i class="far fa-chevron-down text-xs text-rt-muted transition-transform dark:text-rt-dark-muted" :class="filtersOpen && 'rotate-180'" aria-hidden="true"></i>
        </button>
    </div>

    {{-- Filter-Modul: mobil ausklappbar, ab lg immer sichtbar & inline
         (Desktop-Sichtbarkeit wird per CSS erzwungen, siehe app.css) --}}
    <div
        id="{{ $panelId }}"
        x-show="filtersOpen"
        x-collapse
        x-cloak
        class="rt-toolbar-filters flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center lg:ml-auto"
    >
        {{ $slot }}
    </div>
</div>
