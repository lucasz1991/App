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
    $searchContext = in_array($context, ['table', 'topbar', 'chat', 'picker'], true) ? $context : 'table';
    $isTopbarSearch = $searchContext === 'topbar';
    $searchAttributes = $inputAttributes instanceof \Illuminate\View\ComponentAttributeBag
        ? $inputAttributes
        : $attributes;
    $wireModel = $searchAttributes->wire('model')->value();
    $gradientId = 'rt-search-trace-'.substr(md5($searchContext.'|'.$wireModel.'|'.$searchAttributes->get('id')), 0, 12);
@endphp

<div
  x-data="{
        value: @entangle($searchAttributes->wire('model')),
        isTopbar: @js($isTopbarSearch),
        layerId: @js($isTopbarSearch ? 'topbar-search' : null),
        placeholderText: @js($ph),
        placeholderValue: '',
        placeholderIndex: 0,
        placeholderTimer: null,
        reducedMotionQuery: null,
        reducedMotionListener: null,
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

            this.reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            this.reducedMotionListener = (event) => {
                if (event.matches) {
                    this.stopPlaceholder();
                    this.placeholderValue = this.placeholderText;
                    return;
                }

                if (String(this.value ?? '').length === 0) {
                    this.startPlaceholder(true);
                }
            };

            if (typeof this.reducedMotionQuery.addEventListener === 'function') {
                this.reducedMotionQuery.addEventListener('change', this.reducedMotionListener);
            } else {
                this.reducedMotionQuery.addListener(this.reducedMotionListener);
            }

            this.$watch('value', (nextValue) => {
                if (String(nextValue ?? '').length > 0) {
                    this.stopPlaceholder();
                    return;
                }

                this.startPlaceholder(true);
            });

            this.$nextTick(() => {
                if (!this.isTopbar) this.startPlaceholder(true);
            });
        },
        destroy() {
            this.stopPlaceholder();

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

            if (this.reducedMotionQuery && this.reducedMotionListener) {
                if (typeof this.reducedMotionQuery.removeEventListener === 'function') {
                    this.reducedMotionQuery.removeEventListener('change', this.reducedMotionListener);
                } else {
                    this.reducedMotionQuery.removeListener(this.reducedMotionListener);
                }
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
        stopPlaceholder() {
            if (this.placeholderTimer !== null) {
                window.clearTimeout(this.placeholderTimer);
                this.placeholderTimer = null;
            }
        },
        startPlaceholder(restart = false) {
            if (!this.isExpanded() || String(this.value ?? '').length > 0) return;

            this.stopPlaceholder();

            if (this.reducedMotionQuery?.matches) {
                this.placeholderValue = this.placeholderText;
                return;
            }

            if (!restart && this.placeholderValue === this.placeholderText) return;

            if (restart) {
                this.placeholderValue = '';
                this.placeholderIndex = 0;
            }

            const glyphs = Array.from(this.placeholderText);
            const typeNext = () => {
                if (!this.isExpanded() || String(this.value ?? '').length > 0) return;

                this.placeholderIndex = Math.min(this.placeholderIndex + 1, glyphs.length);
                this.placeholderValue = glyphs.slice(0, this.placeholderIndex).join('');

                if (this.placeholderIndex < glyphs.length) {
                    const cadence = 30 + ((this.placeholderIndex % 4) * 8);
                    this.placeholderTimer = window.setTimeout(typeNext, cadence);
                } else {
                    this.placeholderTimer = null;
                }
            };

            this.placeholderTimer = window.setTimeout(typeNext, 90);
        },
        open() {
            if (this.isTopbar) {
                window.dispatchEvent(new CustomEvent('rt-topbar-layer-open', {
                    detail: { group: 'topbar', id: this.layerId },
                }));
            }

            this.expanded = true;
            this.syncPageScrollLock();
            this.startPlaceholder(true);

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
            this.startPlaceholder(true);
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
    data-rt-premium-search
    data-tables-search
    @if ($wireModel)
        wire:loading.class="is-loading"
        wire:target="{{ $wireModel }}"
    @endif
>
    <span class="rt-expandable-search__surface" aria-hidden="true"></span>
    <svg
        class="rt-expandable-search__bezel"
        viewBox="0 0 100 44"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="var(--rt-search-trace-a)" />
                <stop offset="0.36" stop-color="var(--rt-search-trace-b)" />
                <stop offset="0.7" stop-color="var(--rt-search-trace-c)" />
                <stop offset="1" stop-color="var(--rt-search-trace-a)" />
            </linearGradient>
        </defs>
        <rect class="rt-expandable-search__bezel-track" x="1" y="1" width="98" height="42" rx="11" pathLength="100" />
        <rect class="rt-expandable-search__bezel-trace" x="1" y="1" width="98" height="42" rx="11" pathLength="100" stroke="url(#{{ $gradientId }})" />
    </svg>

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
    @if ($isTopbarSearch)
        </button>
    @else
        </span>
    @endif

    <input
        type="search"
        x-ref="input"
        x-model="value"
        x-on:focus="expanded = true; syncPageScrollLock(); startPlaceholder(false)"
        x-on:blur="closeWhenEmpty()"
        x-bind:tabindex="isExpanded() ? 0 : -1"
        aria-label="{{ $ph }}"
        aria-placeholder="{{ $ph }}"
        placeholder=""
        autocomplete="off"
        inputmode="search"
        enterkeyhint="search"
        autocapitalize="none"
        spellcheck="false"
        @if ($isTopbarSearch)
            role="searchbox"
        @endif
        {{ $searchAttributes->merge(['class' => 'rt-expandable-search__input']) }}
    />

    <span
        x-show="isExpanded() && String(value ?? '').length === 0"
        class="rt-expandable-search__placeholder"
        aria-hidden="true"
    >
        <span x-text="placeholderValue"></span>
        <span class="rt-expandable-search__cursor"></span>
    </span>

    @if ($status !== null)
        <span
            x-show="isExpanded() && String(value ?? '').length === 0"
            class="rt-expandable-search__status"
            @if (filled($statusLabel)) aria-label="{{ $statusLabel }}" @endif
        >
            {{ $status }}
        </span>
    @else
        <span
            x-show="isExpanded() && String(value ?? '').length === 0"
            class="rt-expandable-search__activity"
            aria-hidden="true"
            data-rt-search-activity
        >
            <span></span><span></span><span></span>
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
