@props([
    // ['anwesenheit' => 'Anwesenheit'] oder ['anwesenheit' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    'persistKey' => null,
    // Aus Kompatibilitaetsgruenden weiterhin akzeptiert; das Carousel ist in allen Groessen aktiv.
    'collapseAt' => 'md',
    'ariaLabel' => null,
    'contentClass' => 'mt-4 sm:mt-6',
])

@php
    $firstKey = array_key_first($tabs);
    $initial = $default ?? $firstKey ?? 'tab-1';
    $routeName = optional(request()->route())->getName() ?? request()->path();
    $tabsSig = implode(',', array_keys($tabs));
    $key = $persistKey ?: 'tabs:' . $routeName . $tabsSig;
@endphp

<div
    x-data="{
        openTab: $persist('{{ $initial }}').as('{{ $key }}'),
        tabDirection: 'next',
        touchStartX: null,
        touchStartY: null,
        pointerStartX: null,
        pointerStartY: null,
        items: (function() {
            const out = [];
            @foreach($tabs as $k => $tab)
                @php
                    $isArray = is_array($tab);
                    $label = $isArray ? ($tab['label'] ?? \Illuminate\Support\Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
                @endphp
                out.push({ id: '{{ $k }}', label: @js($label), icon: @js($iconClass) });
            @endforeach
            return out;
        })(),
        ensureActiveTab() {
            if (!this.items.some(item => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },
        activeIndex() {
            return Math.max(0, this.items.findIndex(item => item.id === this.openTab));
        },
        tabPosition(index) {
            const offset = index - this.activeIndex();
            if (offset === 0) return 'active';
            if (offset === -1) return 'before';
            if (offset === 1) return 'after';
            return offset < 0 ? 'far-before' : 'far-after';
        },
        selectTab(id, focusTab = false) {
            if (id === this.openTab) return;

            const currentIndex = this.activeIndex();
            const nextIndex = this.items.findIndex(item => item.id === id);
            if (nextIndex < 0) return;

            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = id;
            this.$nextTick(() => {
                this.centerActiveTab();
                if (focusTab) {
                    this.$root.querySelector(`[data-tab-id='${this.openTab}']`)?.focus();
                }
            });
        },
        moveTab(direction, focusTab = true) {
            if (this.items.length < 2) return;
            const index = this.activeIndex();
            const nextIndex = (index + direction + this.items.length) % this.items.length;
            this.tabDirection = direction > 0 ? 'next' : 'previous';
            this.openTab = this.items[nextIndex].id;
            this.$nextTick(() => {
                this.centerActiveTab();
                if (focusTab) {
                    this.$root.querySelector(`[data-tab-id='${this.openTab}']`)?.focus();
                }
            });
        },
        centerActiveTab(behavior = 'smooth') {
            const carousel = this.$refs.carousel;
            const active = this.$root.querySelector(`[data-tab-id='${this.openTab}']`);
            if (!carousel || !active) return;

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const left = active.offsetLeft - ((carousel.clientWidth - active.offsetWidth) / 2);
            carousel.scrollTo({ left: Math.max(0, left), behavior: reduceMotion ? 'auto' : behavior });
        },
        isInteractiveTarget(target) {
            if (!(target instanceof Element)) return true;
            return target.closest('input, textarea, select, button, a, audio, video, [contenteditable=true], [role=dialog], [data-no-tab-swipe]');
        },
        touchStart(event) {
            if (event.touches.length !== 1) {
                this.cancelSwipe();
                return;
            }

            if (this.isInteractiveTarget(event.target)) {
                this.cancelSwipe();
                return;
            }

            this.touchStartX = event.touches[0].clientX;
            this.touchStartY = event.touches[0].clientY;
        },
        touchEnd(event) {
            if (this.touchStartX === null || event.changedTouches.length !== 1) {
                this.cancelSwipe();
                return;
            }

            const deltaX = event.changedTouches[0].clientX - this.touchStartX;
            const deltaY = event.changedTouches[0].clientY - this.touchStartY;
            const threshold = Math.max(54, Math.min(96, window.innerWidth * 0.18));

            if (Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
                this.moveTab(deltaX < 0 ? 1 : -1, false);
            }

            this.cancelSwipe();
        },
        cancelSwipe() {
            this.touchStartX = null;
            this.touchStartY = null;
        },
        pointerStart(event) {
            if (event.pointerType !== 'mouse' || event.button !== 0 || this.isInteractiveTarget(event.target)) {
                this.cancelPointerSwipe();
                return;
            }

            this.pointerStartX = event.clientX;
            this.pointerStartY = event.clientY;
        },
        pointerEnd(event) {
            if (this.pointerStartX === null) {
                this.cancelPointerSwipe();
                return;
            }

            const deltaX = event.clientX - this.pointerStartX;
            const deltaY = event.clientY - this.pointerStartY;

            if (Math.abs(deltaX) >= 72 && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
                this.moveTab(deltaX < 0 ? 1 : -1, false);
            }

            this.cancelPointerSwipe();
        },
        cancelPointerSwipe() {
            this.pointerStartX = null;
            this.pointerStartY = null;
        },
    }"
    x-init="if (@js($forceDefault)) { openTab = @js($initial); } ensureActiveTab(); $nextTick(() => centerActiveTab('auto'))"
    :data-tab-direction="tabDirection"
    class="w-full min-w-0"
    @touchstart.passive="touchStart($event)"
    @touchend.passive="touchEnd($event)"
    @touchcancel.passive="cancelSwipe()"
    @pointerdown="pointerStart($event)"
    @pointerup="pointerEnd($event)"
    @pointercancel="cancelPointerSwipe()"
    @resize.window.debounce.150ms="centerActiveTab('auto')"
    data-swipe-tabs
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        class="rt-tabs-shell rounded-2xl bg-rt-surface p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
        role="tablist"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent="moveTab(1)"
        @keydown.left.prevent="moveTab(-1)"
        @keydown.home.prevent="selectTab(items[0].id)"
        @keydown.end.prevent="selectTab(items[items.length - 1].id)"
        wire:ignore
    >
        <div
            x-ref="carousel"
            class="rt-tabs-carousel min-w-0"
            data-tab-carousel
        >
            <div class="rt-tabs-carousel-track">
                <template x-for="(tab, index) in items" :key="tab.id">
                    <button
                        type="button"
                        @click.prevent="selectTab($event.currentTarget.dataset.tabId, true)"
                        :data-active="openTab === tab.id ? 'true' : 'false'"
                        :data-position="tabPosition(index)"
                        class="rt-carousel-tab group relative flex min-h-12 min-w-0 shrink-0 snap-center items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-center text-[13px] font-semibold leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400/60 sm:min-h-11 sm:px-4 sm:text-sm"
                        role="tab"
                        :id="`tab-${tab.id}`"
                        :data-tab-id="tab.id"
                        :aria-controls="`panel-${tab.id}`"
                        :aria-selected="openTab === tab.id"
                        :tabindex="openTab === tab.id ? 0 : -1"
                    >
                        <span
                            class="rt-carousel-tab-icon flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition-colors"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                        >
                            <template x-if="tab.icon">
                                <i :class="tab.icon" aria-hidden="true"></i>
                            </template>
                        </span>
                        <span class="min-w-0 truncate" x-text="tab.label"></span>
                        <span class="rt-carousel-tab-depth" aria-hidden="true"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <div class="rt-tab-panels {{ $contentClass }} relative min-w-0 overflow-hidden" data-tab-panels>
        {{ $slot }}
    </div>
</div>
