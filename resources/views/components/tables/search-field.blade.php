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
    $isTopbarSearch = $searchContext === 'topbar';
@endphp

<div
  x-data="{
        value: @entangle($attributes->wire('model')),
        isTopbar: @js($isTopbarSearch),
        layerId: @js($isTopbarSearch ? 'topbar-search' : null),
        expanded: false,
        mobile: false,
        mobileQuery: null,
        mobileQueryListener: null,
        navigationListener: null,
        focusFrame: null,
        init() {
            this.expanded = !this.isTopbar && String(this.value ?? '').length > 0;
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
            return this.expanded || (!this.isTopbar && String(this.value ?? '').length > 0);
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
    }"
    x-on:keydown.escape.stop.prevent="handleEscape()"
    x-on:dropdown-open.window="isTopbar && close(false)"
    x-on:rt-navigation:prepare.window="close(false)"
    x-on:rt-topbar-layer-open.window="handleLayerOpen($event)"
    x-on:rt-topbar-search-close.window="handleSearchClose($event)"
    class="rt-expandable-search"
    x-trap.inert.noscroll="isMobileLayerOpen()"
    x-bind:role="isMobileLayerOpen() ? 'dialog' : null"
    x-bind:aria-modal="isMobileLayerOpen() ? 'true' : null"
    x-bind:aria-label="isMobileLayerOpen() ? @js($ph) : null"
    data-search-context="{{ $searchContext }}"
    data-tables-search
>
    <button
        x-ref="trigger"
        type="button"
        @if ($isTopbarSearch)
            x-on:click="handleTriggerClick()"
        @else
            x-on:click="open()"
        @endif
        class="rt-expandable-search__trigger"
        aria-label="{{ $ph }}"
        @if ($isTopbarSearch)
            x-bind:aria-label="isMobileLayerOpen() ? @js(__('app.close')) : @js($ph)"
        @endif
        x-bind:aria-expanded="isExpanded().toString()"
    >
        <svg
            @if ($isTopbarSearch) x-show="!isMobileLayerOpen()" @endif
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 192.904 192.904"
            class="h-4 w-4"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="m190.707 180.101-47.078-47.077c11.702-14.072 18.752-32.142 18.752-51.831C162.381 36.423 125.959 0 81.191 0 36.422 0 0 36.423 0 81.193c0 44.767 36.422 81.187 81.191 81.187 19.688 0 37.759-7.049 51.831-18.751l47.079 47.078a7.474 7.474 0 0 0 5.303 2.197 7.498 7.498 0 0 0 5.303-12.803zM15 81.193C15 44.694 44.693 15 81.191 15c36.497 0 66.189 29.694 66.189 66.193 0 36.496-29.692 66.187-66.189 66.187C44.693 147.38 15 117.689 15 81.193z"></path>
        </svg>
        @if ($isTopbarSearch)
            <svg
                x-show="isMobileLayerOpen()"
                x-cloak
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                class="h-[18px] w-[18px]"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
            </svg>
        @endif
    </button>

    <input
        type="text"
        x-ref="input"
        x-model="value"
        x-on:focus="expanded = true; syncPageScrollLock()"
        x-on:blur="closeWhenEmpty()"
        x-bind:tabindex="isExpanded() ? 0 : -1"
        placeholder="{{ $ph }}"
        autocomplete="off"
        @if ($isTopbarSearch)
            inputmode="search"
            enterkeyhint="search"
            role="searchbox"
            autocapitalize="none"
            spellcheck="false"
        @endif
        {{ $attributes->merge(['class' => 'rt-expandable-search__input']) }}
        @if($noResults) :class="String(value ?? '').length > 0 && 'border-rt-red/60 ring-2 ring-rt-red/20 dark:border-rt-red/60'" @endif
    />

    <button
        type="button"
        x-show="isExpanded() && String(value ?? '').length > 0"
        x-cloak
        x-on:click="clear()"
        class="rt-expandable-search__clear"
        aria-label="{{ __('app.clear_selection') }}"
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
                x-transition:enter="transition-opacity duration-200 ease-out"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity duration-150 ease-in"
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
