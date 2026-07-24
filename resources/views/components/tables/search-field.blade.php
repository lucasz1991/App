@props([
    'resultsCount' => null,
    'placeholder' => null,
])

@php
    // Hinweis: Die feste Desktop-Breite nutzt den !-Modifier (sm:!w-64), weil die
    // Legacy build/css/tailwind.min.css `.w-full{width:100%!important}` definiert –
    // ohne ! wuerde `w-full` die responsive Breite ueberschreiben. Nicht entfernen.
    // resultsCount bleibt aus Kompatibilitaet erhalten; bei aktiver Suche ohne
    // Treffer wird ein dezenter roter Ring gezeigt.
    $hasResultsSignal = $resultsCount !== null;
    $noResults = $hasResultsSignal && (int) $resultsCount === 0;
    $ph = $placeholder ?? __('app.search');
@endphp

<div
    x-data="{ value: @entangle($attributes->wire('model')) }"
    x-cloak
    class="relative w-full sm:!w-64"
    data-tables-search
>
    {{-- Such-Icon --}}
    <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-rt-soft dark:text-rt-dark-soft">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192.904 192.904" class="h-4 w-4" fill="currentColor" aria-hidden="true">
            <path d="m190.707 180.101-47.078-47.077c11.702-14.072 18.752-32.142 18.752-51.831C162.381 36.423 125.959 0 81.191 0 36.422 0 0 36.423 0 81.193c0 44.767 36.422 81.187 81.191 81.187 19.688 0 37.759-7.049 51.831-18.751l47.079 47.078a7.474 7.474 0 0 0 5.303 2.197 7.498 7.498 0 0 0 5.303-12.803zM15 81.193C15 44.694 44.693 15 81.191 15c36.497 0 66.189 29.694 66.189 66.193 0 36.496-29.692 66.187-66.189 66.187C44.693 147.38 15 117.689 15 81.193z"></path>
        </svg>
    </span>

    <input
        type="text"
        x-model="value"
        placeholder="{{ $ph }}"
        {{ $attributes->merge(['class' => 'rt-ui-control h-11 w-full rounded-xl border border-rt-border bg-rt-control pl-10 pr-9 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring placeholder:text-rt-soft hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20']) }}
        @if($noResults) :class="value.length > 0 && 'border-rt-red/60 ring-2 ring-rt-red/20 dark:border-rt-red/60'" @endif
    />

    {{-- Loeschen --}}
    <button
        type="button"
        x-show="value.length > 0"
        x-cloak
        @click="value = ''"
        class="absolute inset-y-0 right-0 flex w-9 items-center justify-center rounded-r-xl text-rt-soft transition hover:text-rt-accent dark:text-rt-dark-soft dark:hover:text-rt-dark-accent"
        aria-label="{{ __('app.clear_selection') }}"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>
