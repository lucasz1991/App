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
        stickyEnabled: true,
        touchStartX: null,
        touchStartY: null,
        pointerStartX: null,
        pointerStartY: null,
        carouselDragging: false,
        carouselPointerId: null,
        carouselPointerStartX: null,
        carouselPointerScrollLeft: 0,
        carouselPointerMoved: false,
        carouselTouchStartX: null,
        carouselTouchStartY: null,
        suppressCarouselClick: false,
        carouselSettleTimer: null,
        carouselFrame: null,
        carouselProgrammaticScroll: false,
        carouselProgrammaticTimer: null,
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

            window.clearTimeout(this.carouselSettleTimer);
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
            this.carouselProgrammaticScroll = true;
            window.clearTimeout(this.carouselProgrammaticTimer);
            carousel.scrollTo({ left: Math.max(0, left), behavior: reduceMotion ? 'auto' : behavior });
            this.carouselProgrammaticTimer = window.setTimeout(() => {
                this.carouselProgrammaticScroll = false;
                this.updateCarouselDepth();
            }, behavior === 'smooth' && !reduceMotion ? 520 : 40);
        },
        updateCarouselDepth() {
            window.cancelAnimationFrame(this.carouselFrame || 0);
            this.carouselFrame = window.requestAnimationFrame(() => {
                const carousel = this.$refs.carousel;
                if (!carousel) return;

                const carouselRect = carousel.getBoundingClientRect();
                const carouselCenter = carouselRect.left + (carouselRect.width / 2);

                carousel.querySelectorAll('.rt-carousel-tab').forEach((tab) => {
                    const rect = tab.getBoundingClientRect();
                    const distance = ((rect.left + (rect.width / 2)) - carouselCenter) / Math.max(1, rect.width);
                    const clamped = Math.max(-2.2, Math.min(2.2, distance));
                    const depth = Math.abs(clamped);

                    tab.style.setProperty('--rt-carousel-drag-rotate', `${clamped * -10}deg`);
                    tab.style.setProperty('--rt-carousel-drag-scale', String(Math.max(0.86, 1 - (depth * 0.075))));
                    tab.style.setProperty('--rt-carousel-drag-opacity', String(Math.max(0.48, 1 - (depth * 0.22))));
                    tab.style.setProperty('--rt-carousel-drag-y', `${Math.min(3, depth * 1.5)}px`);
                    tab.style.setProperty('--rt-carousel-drag-z', String(Math.max(1, 10 - Math.round(depth * 3))));
                });
            });
        },
        onCarouselScroll() {
            this.updateCarouselDepth();
            if (this.carouselProgrammaticScroll) return;

            this.carouselDragging = true;
            window.clearTimeout(this.carouselSettleTimer);
            this.carouselSettleTimer = window.setTimeout(() => this.settleCarousel(), 130);
        },
        settleCarousel() {
            const carousel = this.$refs.carousel;
            if (!carousel) return;

            const carouselRect = carousel.getBoundingClientRect();
            const carouselCenter = carouselRect.left + (carouselRect.width / 2);
            const tabs = Array.from(carousel.querySelectorAll('.rt-carousel-tab'));
            if (!tabs.length) return;

            const closest = tabs.reduce((current, tab) => {
                const rect = tab.getBoundingClientRect();
                const distance = Math.abs((rect.left + (rect.width / 2)) - carouselCenter);
                return distance < current.distance ? { tab, distance } : current;
            }, { tab: tabs[0], distance: Number.POSITIVE_INFINITY }).tab;

            const id = closest.dataset.tabId;
            const currentIndex = this.activeIndex();
            const nextIndex = this.items.findIndex(item => item.id === id);

            if (id && id !== this.openTab && nextIndex >= 0) {
                this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
                this.openTab = id;
            }

            this.carouselDragging = false;
            this.$nextTick(() => this.centerActiveTab());
        },
        carouselPointerDown(event) {
            if (event.pointerType === 'touch' || event.button !== 0) return;

            const carousel = this.$refs.carousel;
            if (!carousel) return;

            this.carouselPointerId = event.pointerId;
            this.carouselPointerStartX = event.clientX;
            this.carouselPointerScrollLeft = carousel.scrollLeft;
            this.carouselPointerMoved = false;
            this.carouselProgrammaticScroll = false;
            window.clearTimeout(this.carouselProgrammaticTimer);
            window.clearTimeout(this.carouselSettleTimer);
            this.carouselDragging = true;
            carousel.setPointerCapture?.(event.pointerId);
            this.updateCarouselDepth();
        },
        carouselPointerMove(event) {
            if (this.carouselPointerId !== event.pointerId || this.carouselPointerStartX === null) return;

            const deltaX = event.clientX - this.carouselPointerStartX;
            if (Math.abs(deltaX) > 5) {
                this.carouselPointerMoved = true;
                this.suppressCarouselClick = true;
            }

            event.preventDefault();
            this.$refs.carousel.scrollLeft = this.carouselPointerScrollLeft - deltaX;
            this.updateCarouselDepth();
        },
        carouselPointerUp(event) {
            if (this.carouselPointerId !== event.pointerId) return;

            const wasDragged = this.carouselPointerMoved;
            this.$refs.carousel?.releasePointerCapture?.(event.pointerId);
            this.carouselPointerId = null;
            this.carouselPointerStartX = null;
            this.carouselPointerMoved = false;
            window.clearTimeout(this.carouselSettleTimer);
            if (wasDragged) {
                this.carouselSettleTimer = window.setTimeout(() => this.settleCarousel(), 80);
            } else {
                this.carouselDragging = false;
            }
            window.setTimeout(() => { this.suppressCarouselClick = false; }, 0);
        },
        carouselTouchStart(event) {
            if (event.touches.length !== 1) return;

            this.carouselTouchStartX = event.touches[0].clientX;
            this.carouselTouchStartY = event.touches[0].clientY;
            this.carouselProgrammaticScroll = false;
            window.clearTimeout(this.carouselProgrammaticTimer);
            window.clearTimeout(this.carouselSettleTimer);
            this.carouselDragging = true;
            this.updateCarouselDepth();
        },
        carouselTouchMove() {
            this.updateCarouselDepth();
        },
        carouselTouchEnd(event) {
            if (this.carouselTouchStartX !== null && event.changedTouches.length === 1) {
                const deltaX = event.changedTouches[0].clientX - this.carouselTouchStartX;
                const deltaY = event.changedTouches[0].clientY - this.carouselTouchStartY;
                if (Math.abs(deltaX) > 8 && Math.abs(deltaX) > Math.abs(deltaY)) {
                    this.suppressCarouselClick = true;
                    window.setTimeout(() => { this.suppressCarouselClick = false; }, 350);
                }
            }

            this.carouselTouchStartX = null;
            this.carouselTouchStartY = null;
            window.clearTimeout(this.carouselSettleTimer);
            this.carouselSettleTimer = window.setTimeout(() => this.settleCarousel(), 130);
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
    x-init="if (@js($forceDefault)) { openTab = @js($initial); } ensureActiveTab(); stickyEnabled = !$root.closest('[role=dialog]'); $nextTick(() => { centerActiveTab('auto'); updateCarouselDepth(); })"
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
        :data-sticky-enabled="stickyEnabled ? 'true' : 'false'"
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
            :data-dragging="carouselDragging ? 'true' : 'false'"
            @scroll.passive="onCarouselScroll()"
            @pointerdown="carouselPointerDown($event)"
            @pointermove="carouselPointerMove($event)"
            @pointerup="carouselPointerUp($event)"
            @pointercancel="carouselPointerUp($event)"
            @touchstart.stop.passive="carouselTouchStart($event)"
            @touchmove.stop.passive="carouselTouchMove($event)"
            @touchend.stop.passive="carouselTouchEnd($event)"
            @touchcancel.stop.passive="carouselTouchEnd($event)"
            @dragstart.prevent
            data-tab-carousel
        >
            <div class="rt-tabs-carousel-track">
                <template x-for="(tab, index) in items" :key="tab.id">
                    <button
                        type="button"
                        @click.prevent="suppressCarouselClick ? (suppressCarouselClick = false) : selectTab($event.currentTarget.dataset.tabId, true)"
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
