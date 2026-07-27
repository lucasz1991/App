@props([
    // ['anwesenheit' => 'Anwesenheit'] oder ['anwesenheit' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    'persistKey' => null,
    // Aus Kompatibilitaetsgruenden weiterhin akzeptiert.
    'collapseAt' => 'md',
    'ariaLabel' => null,
    'contentClass' => 'mt-4 sm:mt-6',
])

@php
    $firstKey = array_key_first($tabs);
    $initial = (string) ($default ?? $firstKey ?? 'tab-1');
    $routeName = optional(request()->route())->getName() ?? request()->path();
    $tabsSig = implode(',', array_keys($tabs));
    $key = $persistKey ?: 'tabs:' . $routeName . $tabsSig;
@endphp

<div
    x-data="{
        openTab: $persist(@js($initial)).as(@js($key)),
        tabDirection: 'next',
        stickyEnabled: true,
        touchPointerId: null,
        touchStartX: null,
        touchStartY: null,
        touchStartScrollLeft: 0,
        touchDragging: false,
        suppressTouchClick: false,
        suppressTouchClickTimer: null,
        scrollFrame: null,
        atScrollStart: true,
        atScrollEnd: true,
        items: [
            @foreach($tabs as $k => $tab)
                @php
                    $isArray = is_array($tab);
                    $label = $isArray ? ($tab['label'] ?? \Illuminate\Support\Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
                @endphp
                { id: @js((string) $k), label: @js($label), icon: @js($iconClass) },
            @endforeach
        ],
        ensureActiveTab() {
            if (!this.items.some(item => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },
        activeIndex() {
            return Math.max(0, this.items.findIndex(item => item.id === this.openTab));
        },
        tabElement(id) {
            return Array.from(this.$refs.carousel?.querySelectorAll('[role=tab]') ?? [])
                .find(tab => tab.dataset.tabId === id);
        },
        selectTab(id, focusTab = false) {
            if (!this.items.some(item => item.id === id)) return;

            const currentIndex = this.activeIndex();
            const nextIndex = this.items.findIndex(item => item.id === id);
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = id;

            this.$nextTick(() => {
                this.revealActiveTab();
                this.animateSelection();

                if (focusTab) {
                    this.tabElement(this.openTab)?.focus({ preventScroll: true });
                }
            });
        },
        activateFromClick(event, id) {
            // Ein echter Touch-Drag darf keinen synthetischen Klick ausloesen.
            // Maus- und Pen-Klicks werden davon explizit nie abgefangen.
            if (this.suppressTouchClick && event.pointerType === 'touch') {
                event.preventDefault();
                event.stopPropagation();
                this.suppressTouchClick = false;
                return;
            }

            this.selectTab(id, true);
        },
        moveTab(direction) {
            if (this.items.length < 2) return;

            const index = this.activeIndex();
            const nextIndex = (index + direction + this.items.length) % this.items.length;
            this.selectTab(this.items[nextIndex].id, true);
        },
        moveToBoundary(position) {
            if (!this.items.length) return;
            this.selectTab(position === 'start' ? this.items[0].id : this.items[this.items.length - 1].id, true);
        },
        revealActiveTab(behavior = 'smooth') {
            const carousel = this.$refs.carousel;
            const active = this.tabElement(this.openTab);
            if (!carousel || !active) return;

            const inset = 12;
            const activeStart = active.offsetLeft;
            const activeEnd = activeStart + active.offsetWidth;
            const visibleStart = carousel.scrollLeft + inset;
            const visibleEnd = carousel.scrollLeft + carousel.clientWidth - inset;
            let nextLeft = carousel.scrollLeft;

            if (activeStart < visibleStart) {
                nextLeft = Math.max(0, activeStart - inset);
            } else if (activeEnd > visibleEnd) {
                nextLeft = Math.max(0, activeEnd - carousel.clientWidth + inset);
            }

            if (Math.abs(nextLeft - carousel.scrollLeft) > 1) {
                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                carousel.scrollTo({ left: nextLeft, behavior: reduceMotion ? 'auto' : behavior });
            }

            this.syncScrollEdges();
        },
        animateSelection() {
            if (!window.gsap || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            const active = this.tabElement(this.openTab);
            const marker = active?.querySelector('[data-rt-tab-active-mark]');
            if (!active || !marker) return;

            window.gsap.killTweensOf([active, marker]);
            window.gsap.fromTo(
                active,
                { scale: 0.985 },
                { scale: 1, duration: 0.24, ease: 'power2.out', clearProps: 'transform' },
            );
            window.gsap.fromTo(
                marker,
                { scaleX: 0.42, autoAlpha: 0.45 },
                { scaleX: 1, autoAlpha: 1, duration: 0.34, ease: 'power3.out', clearProps: 'transform,opacity,visibility' },
            );
        },
        syncScrollEdges() {
            window.cancelAnimationFrame(this.scrollFrame || 0);
            this.scrollFrame = window.requestAnimationFrame(() => {
                const carousel = this.$refs.carousel;
                if (!carousel) return;

                const maxScroll = Math.max(0, carousel.scrollWidth - carousel.clientWidth);
                this.atScrollStart = carousel.scrollLeft <= 2;
                this.atScrollEnd = maxScroll <= 2 || carousel.scrollLeft >= maxScroll - 2;
            });
        },
        isTouchPointer(event) {
            return event.pointerType === 'touch'
                && event.isPrimary !== false;
        },
        touchPointerDown(event) {
            const carousel = this.$refs.carousel;
            if (!carousel || !this.isTouchPointer(event)) return;
            if (carousel.scrollWidth <= carousel.clientWidth + 1) return;

            window.clearTimeout(this.suppressTouchClickTimer);
            this.suppressTouchClick = false;
            this.touchPointerId = event.pointerId;
            this.touchStartX = event.clientX;
            this.touchStartY = event.clientY;
            this.touchStartScrollLeft = carousel.scrollLeft;
            this.touchDragging = false;
        },
        touchPointerMove(event) {
            if (this.touchPointerId !== event.pointerId || !this.isTouchPointer(event)) return;

            const deltaX = event.clientX - this.touchStartX;
            const deltaY = event.clientY - this.touchStartY;

            if (!this.touchDragging) {
                // Vertikales Scrollen bleibt immer beim Browser.
                if (Math.abs(deltaY) > 9 && Math.abs(deltaY) > Math.abs(deltaX)) {
                    this.resetTouchPointer();
                    return;
                }

                // Kleine Fingerbewegungen bleiben ein normaler, sicherer Tap.
                if (Math.abs(deltaX) < 12 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.15) {
                    return;
                }

                this.touchDragging = true;
                this.suppressTouchClick = true;
                event.currentTarget.setPointerCapture?.(event.pointerId);
            }

            if (event.cancelable) event.preventDefault();
            this.$refs.carousel.scrollLeft = this.touchStartScrollLeft - deltaX;
            this.syncScrollEdges();
        },
        touchPointerEnd(event) {
            if (this.touchPointerId !== event.pointerId) return;

            const didDrag = this.touchDragging;
            this.resetTouchPointer(event);

            if (didDrag) {
                // Moderne Pointer-Clicks tragen pointerType=touch. Der Timer ist
                // nur ein Fallback, falls nach dem Drag gar kein Click folgt.
                this.suppressTouchClickTimer = window.setTimeout(() => {
                    this.suppressTouchClick = false;
                }, 450);
            } else {
                this.suppressTouchClick = false;
            }
        },
        resetTouchPointer(event = null) {
            const pointerId = this.touchPointerId;
            this.touchPointerId = null;
            this.touchStartX = null;
            this.touchStartY = null;
            this.touchStartScrollLeft = 0;
            this.touchDragging = false;

            if (event && pointerId !== null && event.currentTarget?.hasPointerCapture?.(pointerId)) {
                event.currentTarget.releasePointerCapture(pointerId);
            }
        },
        destroy() {
            window.clearTimeout(this.suppressTouchClickTimer);
            window.cancelAnimationFrame(this.scrollFrame || 0);

            if (window.gsap) {
                window.gsap.killTweensOf(this.$root.querySelectorAll('.rt-carousel-tab, [data-rt-tab-active-mark]'));
            }
        },
    }"
    x-init="
        if (@js($forceDefault)) openTab = @js($initial);
        ensureActiveTab();
        stickyEnabled = !$root.closest('[role=dialog]');
        $nextTick(() => {
            revealActiveTab('auto');
            syncScrollEdges();
        });
    "
    :data-tab-direction="tabDirection"
    class="w-full min-w-0"
    data-tabs-input-policy="touch-only-drag"
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        class="rt-tabs-shell rt-tabs-v2"
        :data-sticky-enabled="stickyEnabled ? 'true' : 'false'"
        :data-scroll-start="atScrollStart ? 'true' : 'false'"
        :data-scroll-end="atScrollEnd ? 'true' : 'false'"
        role="tablist"
        aria-orientation="horizontal"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent.stop="moveTab(1)"
        @keydown.left.prevent.stop="moveTab(-1)"
        @keydown.home.prevent.stop="moveToBoundary('start')"
        @keydown.end.prevent.stop="moveToBoundary('end')"
        wire:ignore
    >
        <div
            x-ref="carousel"
            class="rt-tabs-carousel"
            :data-touch-dragging="touchDragging ? 'true' : 'false'"
            @scroll.passive="syncScrollEdges()"
            @pointerdown="touchPointerDown($event)"
            @pointermove="touchPointerMove($event)"
            @pointerup="touchPointerEnd($event)"
            @pointercancel="resetTouchPointer($event)"
            @lostpointercapture="resetTouchPointer()"
            data-tab-carousel
        >
            <div class="rt-tabs-carousel-track">
                <template x-for="tab in items" :key="tab.id">
                    <button
                        type="button"
                        @click="activateFromClick($event, tab.id)"
                        :data-active="openTab === tab.id ? 'true' : 'false'"
                        :data-position="openTab === tab.id ? 'active' : 'inactive'"
                        class="rt-carousel-tab group"
                        role="tab"
                        :id="`tab-${tab.id}`"
                        :data-tab-id="tab.id"
                        :aria-controls="`panel-${tab.id}`"
                        :aria-selected="openTab === tab.id"
                        :tabindex="openTab === tab.id ? 0 : -1"
                    >
                        <span
                            class="rt-carousel-tab-icon"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            aria-hidden="true"
                        >
                            <template x-if="tab.icon">
                                <i :class="tab.icon"></i>
                            </template>
                            <template x-if="!tab.icon">
                                <span class="rt-tab-fallback-dot"></span>
                            </template>
                        </span>
                        <span class="rt-carousel-tab-label" x-text="tab.label"></span>
                        <span
                            class="rt-tab-active-mark"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            data-rt-tab-active-mark
                            aria-hidden="true"
                        ></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <div class="rt-tab-panels {{ $contentClass }} relative min-w-0 overflow-hidden" data-tab-panels>
        {{ $slot }}
    </div>
</div>
