@props([
    'resultsCount' => null,
    'placeholder' => null,
    'context' => 'table',
    'status' => null,
    'statusLabel' => null,
    'inputAttributes' => null,
])

@php
    // resultsCount bleibt aus Kompatibilitaet erhalten; bei aktiver Suche ohne
    // Treffer wird ein dezenter roter Ring gezeigt.
    $hasResultsSignal = $resultsCount !== null;
    $noResults = $hasResultsSignal && (int) $resultsCount === 0;
    $ph = $placeholder ?? __('app.search');
    $searchContext = in_array($context, ['table', 'topbar'], true) ? $context : 'table';
    $isTopbarSearch = $searchContext === 'topbar';
    $searchAttributes = $inputAttributes instanceof \Illuminate\View\ComponentAttributeBag
        ? $inputAttributes
        : $attributes;
    $wireModel = $searchAttributes->wire('model')->value();
@endphp

<div
  x-data="{
        value: @entangle($searchAttributes->wire('model')),
        isTopbar: @js($isTopbarSearch),
        layerId: @js($isTopbarSearch ? 'topbar-search' : null),
        expanded: false,
        mobile: false,
        mobileQuery: null,
        mobileQueryListener: null,
        navigationListener: null,
        focusFrame: null,
        init() {
            this.expanded = !this.isTopbar;
            this.mobileQuery = window.matchMedia('(max-width: 767.98px)');
            this.mobile = this.mobileQuery.matches;
            this.mobileQueryListener = (event) => {
                this.mobile = event.matches;
                this.syncPageScrollLock();
            };

            if (typeof this.mobileQuery.addEventListener === 'function') {
                this.mobileQuery.addEventListener('change', this.mobileQueryListener);
            } else {
                this.mobileQuery.addListener(this.mobileQueryListener);
            }

            this.navigationListener = () => this.close(false);
            document.addEventListener('livewire:navigating', this.navigationListener);
        },
        destroy() {
            if (this.focusFrame !== null) {
                window.cancelAnimationFrame(this.focusFrame);
            }

            if (this.mobileQuery && this.mobileQueryListener) {
                if (typeof this.mobileQuery.removeEventListener === 'function') {
                    this.mobileQuery.removeEventListener('change', this.mobileQueryListener);
                } else {
                    this.mobileQuery.removeListener(this.mobileQueryListener);
                }
            }

            if (this.navigationListener) {
                document.removeEventListener('livewire:navigating', this.navigationListener);
            }

            if (this.isTopbar) {
                document.documentElement.classList.remove('rt-topbar-search-open');
            }
        },
        isExpanded() {
            return !this.isTopbar || this.expanded;
        },
        isMobileLayerOpen() {
            return this.isTopbar && this.mobile && this.expanded;
        },
        syncPageScrollLock() {
            if (!this.isTopbar) return;
            document.documentElement.classList.toggle('rt-topbar-search-open', this.isMobileLayerOpen());
        },
        open() {
            if (this.isTopbar) {
                window.dispatchEvent(new CustomEvent('rt-topbar-layer-open', {
                    detail: { group: 'topbar', id: this.layerId },
                }));
            }

            this.expanded = true;
            this.syncPageScrollLock();

            // Alpine aktualisiert x-bind:class in einem Microtask. Fuer iOS
            // muss das Eingabefeld aber bereits innerhalb desselben
            // vertrauenswuerdigen Taps sichtbar/fokussierbar sein.
            this.$root.classList.add('is-expanded');
            if (this.isMobileLayerOpen()) {
                this.$root.classList.add('is-mobile-layer');
            }

            // Das erste focus() bleibt im direkten, vertrauenswuerdigen
            // Klick-/Tastaturereignis. Das ist fuer die Bildschirmtastatur in
            // iOS/PWA verlaesslicher als ein ausschliessliches $nextTick.
            this.$refs.input?.focus({ preventScroll: true });

            if (this.focusFrame !== null) {
                window.cancelAnimationFrame(this.focusFrame);
            }

            this.focusFrame = window.requestAnimationFrame(() => {
                this.focusFrame = null;
                if (this.expanded) {
                    this.$refs.input?.focus({ preventScroll: true });
                }
            });
        },
        close(restoreFocus = true) {
            this.expanded = false;
            this.syncPageScrollLock();

            if (this.focusFrame !== null) {
                window.cancelAnimationFrame(this.focusFrame);
                this.focusFrame = null;
            }

            if (restoreFocus) {
                this.$nextTick(() => this.$refs.trigger?.focus({ preventScroll: true }));
            }
        },
        submit() {
            const form = this.$root.closest('form');
            if (form?.requestSubmit) {
                form.requestSubmit();
            }
        },
        closeWhenEmpty() {
            window.setTimeout(() => {
                if (String(this.value ?? '').length === 0 && !this.$root.contains(document.activeElement)) {
                    this.close(false);
                }
            }, 0);
        },
        clear() {
            this.value = '';
            this.$refs.input?.focus({ preventScroll: true });
        },
        handleEscape() {
            if (this.isTopbar || String(this.value ?? '').length === 0) {
                this.close(true);
            }
        },
        handleLayerOpen(event) {
            if (
                this.isTopbar
                && event.detail?.group === 'topbar'
                && event.detail?.id !== this.layerId
            ) {
                this.close(false);
            }
        },
        handleSearchClose(event) {
            if (this.isTopbar) {
                this.close(event.detail?.restoreFocus !== false);
            }
        },
        handleTriggerClick() {
            if (this.isMobileLayerOpen()) {
                this.close(true);
                return;
            }

            if (this.isExpanded() && !this.mobile) {
                this.submit();
                return;
            }

            this.open();
        },
    }"
    x-cloak
    x-bind:class="{
        'is-expanded': isExpanded(),
        'is-mobile-layer': isMobileLayerOpen(),
        'has-value': String(value ?? '').length > 0,
        'has-no-results': @js($noResults) && String(value ?? '').length > 0,
    }"
    x-on:keydown.escape="if (isTopbar) { $event.stopPropagation(); $event.preventDefault(); handleEscape() }"
    x-on:dropdown-open.window="isTopbar && close(false)"
    x-on:rt-navigation:prepare.window="close(false)"
    x-on:rt-topbar-layer-open.window="handleLayerOpen($event)"
    x-on:rt-topbar-search-close.window="handleSearchClose($event)"
    class="rt-expandable-search rt-table-search"
    x-trap.noautofocus.inert.noscroll="isMobileLayerOpen()"
    x-bind:role="isMobileLayerOpen() ? 'dialog' : null"
    x-bind:aria-modal="isMobileLayerOpen() ? 'true' : null"
    x-bind:aria-label="isMobileLayerOpen() ? @js($ph) : null"
    data-search-context="{{ $searchContext }}"
    data-rt-search
    data-tables-search
    @if ($wireModel)
        wire:loading.class="is-loading"
        wire:target="{{ $isTopbarSearch ? $wireModel.',openResults' : $wireModel }}"
    @endif
>
    @if ($isTopbarSearch)
        <button
            x-ref="trigger"
            type="button"
            x-on:click="handleTriggerClick()"
            class="rt-expandable-search__trigger"
            aria-label="{{ $ph }}"
            x-bind:aria-label="isMobileLayerOpen() ? @js(__('app.close')) : @js($ph)"
            x-bind:aria-expanded="isExpanded().toString()"
        >
    @else
        <span class="rt-expandable-search__trigger" aria-hidden="true">
    @endif
        <svg
            @if ($isTopbarSearch) x-show="!isMobileLayerOpen()" @endif
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            class="rt-expandable-search__icon h-4 w-4"
            fill="none"
            stroke="currentColor"
            stroke-width="1.7"
            aria-hidden="true"
        >
            <circle cx="10.75" cy="10.75" r="6.75" />
            <path stroke-linecap="round" d="m16 16 4.25 4.25" />
        </svg>
        @if ($isTopbarSearch)
            <svg
                x-show="isMobileLayerOpen()"
                x-cloak
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                class="rt-expandable-search__icon h-[18px] w-[18px]"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
            </svg>
        @endif
        @if ($wireModel)
            <svg class="rt-expandable-search__spinner h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" d="M20 12a8 8 0 1 1-8-8" />
            </svg>
        @endif
    @if ($isTopbarSearch)
        </button>
    @else
        </span>
    @endif

    <input
        type="search"
        x-ref="input"
        x-model="value"
        x-on:focus="expanded = true; syncPageScrollLock()"
        x-on:blur="closeWhenEmpty()"
        x-bind:tabindex="isExpanded() ? 0 : -1"
        x-bind:aria-hidden="isExpanded() ? null : 'true'"
        aria-label="{{ $ph }}"
        aria-placeholder="{{ $ph }}"
        placeholder="{{ $ph }}"
        autocomplete="off"
        inputmode="search"
        enterkeyhint="search"
        autocapitalize="none"
        spellcheck="false"
        @if ($isTopbarSearch)
            role="searchbox"
        @endif
        {{ $searchAttributes->merge(['class' => 'rt-expandable-search__input']) }}
        @if ($noResults) :class="String(value ?? '').length > 0 && 'border-rt-red/60 ring-2 ring-rt-red/20 dark:border-rt-red/60'" @endif
    />

    @if ($status !== null)
        <span
            x-show="isExpanded() && String(value ?? '').length === 0"
            class="rt-expandable-search__status"
            @if (filled($statusLabel)) aria-label="{{ $statusLabel }}" @endif
        >
            {{ $status }}
        </span>
    @endif

    <button
        type="button"
        x-show="isExpanded() && String(value ?? '').length > 0"
        x-cloak
        x-on:click="clear()"
        class="rt-expandable-search__clear"
        aria-label="{{ __('app.clear_search') }}"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>

    @if ($isTopbarSearch)
        <template x-teleport="body">
            <button
                type="button"
                x-show="isMobileLayerOpen()"
                x-cloak
                x-transition:enter="rt-search-backdrop-enter"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="rt-search-backdrop-leave"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-on:click="close(true)"
                class="rt-topbar-search-backdrop fixed inset-0 z-[30] cursor-default bg-slate-950/45 backdrop-blur-[2px]"
                aria-label="{{ __('app.close') }}"
                data-topbar-search-backdrop
            ></button>
        </template>
    @endif
</div>
