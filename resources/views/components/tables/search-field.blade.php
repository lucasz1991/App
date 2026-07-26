@props([
    'resultsCount' => null,
    'placeholder' => null,
    'context' => 'table',
])

@php
    // resultsCount bleibt aus Kompatibilitaet erhalten; bei aktiver Suche ohne
    // Treffer wird ein dezenter roter Ring gezeigt.
    $hasResultsSignal = $resultsCount !== null;
    $noResults = $hasResultsSignal && (int) $resultsCount === 0;
    $ph = $placeholder ?? __('app.search');
    $searchContext = in_array($context, ['table', 'topbar'], true) ? $context : 'table';
@endphp

<div
    x-data="{
        value: @entangle($attributes->wire('model')),
        expanded: false,
        init() {
            this.expanded = String(this.value ?? '').length > 0;
        },
        open() {
            this.expanded = true;
            this.$nextTick(() => this.$refs.input.focus());
        },
        closeWhenEmpty() {
            window.setTimeout(() => {
                if (String(this.value ?? '').length === 0 && !this.$root.contains(document.activeElement)) {
                    this.expanded = false;
                }
            }, 0);
        },
        clear() {
            this.value = '';
            this.$nextTick(() => this.$refs.input.focus());
        },
    }"
    x-cloak
    x-bind:class="{ 'is-expanded': expanded || String(value ?? '').length > 0 }"
    x-on:keydown.escape.stop="if (String(value ?? '').length === 0) { expanded = false; $refs.trigger.focus(); }"
    class="rt-expandable-search"
    data-search-context="{{ $searchContext }}"
    data-tables-search
>
    <button
        x-ref="trigger"
        type="button"
        @if ($searchContext === 'topbar')
            x-on:click="String(value ?? '').length > 0
                ? $wire.openResults(String(value))
                : open()"
        @else
            x-on:click="open()"
        @endif
        class="rt-expandable-search__trigger"
        aria-label="{{ $ph }}"
        x-bind:aria-expanded="(expanded || String(value ?? '').length > 0).toString()"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192.904 192.904" class="h-4 w-4" fill="currentColor" aria-hidden="true">
            <path d="m190.707 180.101-47.078-47.077c11.702-14.072 18.752-32.142 18.752-51.831C162.381 36.423 125.959 0 81.191 0 36.422 0 0 36.423 0 81.193c0 44.767 36.422 81.187 81.191 81.187 19.688 0 37.759-7.049 51.831-18.751l47.079 47.078a7.474 7.474 0 0 0 5.303 2.197 7.498 7.498 0 0 0 5.303-12.803zM15 81.193C15 44.694 44.693 15 81.191 15c36.497 0 66.189 29.694 66.189 66.193 0 36.496-29.692 66.187-66.189 66.187C44.693 147.38 15 117.689 15 81.193z"></path>
        </svg>
    </button>

    <input
        type="text"
        x-ref="input"
        x-model="value"
        x-on:focus="expanded = true"
        x-on:blur="closeWhenEmpty()"
        @if ($searchContext === 'topbar')
            x-on:keydown.enter.prevent="$wire.openResults(String(value ?? ''))"
        @endif
        x-bind:tabindex="expanded || String(value ?? '').length > 0 ? 0 : -1"
        placeholder="{{ $ph }}"
        autocomplete="off"
        {{ $attributes->merge(['class' => 'rt-expandable-search__input']) }}
        @if($noResults) :class="String(value ?? '').length > 0 && 'border-rt-red/60 ring-2 ring-rt-red/20 dark:border-rt-red/60'" @endif
    />

    <button
        type="button"
        x-show="String(value ?? '').length > 0"
        x-cloak
        x-on:click="clear()"
        class="rt-expandable-search__clear"
        aria-label="{{ __('app.clear_selection') }}"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>
