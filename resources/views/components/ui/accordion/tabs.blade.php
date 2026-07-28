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
        mobileTabs: false,
        scrubbingTabs: false,
        swiper: null,
        scrollFrame: null,
        mobileQuery: null,
        mobileQueryHandler: null,
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
        selectTab(id, focusTab = false, revealTab = true) {
            if (!this.items.some(item => item.id === id)) return;

            const currentIndex = this.activeIndex();
            const nextIndex = this.items.findIndex(item => item.id === id);
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = id;

            this.$nextTick(() => {
                if (revealTab) {
                    this.revealActiveTab();
                }
                this.animateSelection();
                if (!this.scrubbingTabs) {
                    this.keepSelectedPanelVisible();
                }

                if (focusTab) {
                    this.tabElement(this.openTab)?.focus({ preventScroll: true });
                }
            });
        },
        selectTabFromSwiper(index) {
            const item = this.items[index];
            if (!item || item.id === this.openTab) return;

            this.scrubbingTabs = true;
            this.selectTab(item.id, false, false);
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
            if (!this.mobileTabs || !this.swiper) return;

            this.swiper.update();
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const duration = reduceMotion || behavior === 'auto' ? 0 : 280;
            this.swiper.slideTo(this.activeIndex(), duration);
            this.syncScrollEdges();
        },
        configureTabsForViewport(mobile) {
            this.mobileTabs = mobile;

            if (!mobile) {
                this.scrubbingTabs = false;
                this.swiper?.destroy(true, true);
                this.swiper = null;
                this.atScrollStart = true;
                this.atScrollEnd = true;
                this.$refs.carousel?.removeAttribute('data-swiping');
                return;
            }

            this.$nextTick(() => {
                this.initSwiper();
                this.revealActiveTab('auto');
            });
        },
        initResponsiveTabs() {
            this.mobileQuery = window.matchMedia('(max-width: 767.98px)');
            this.mobileQueryHandler = event => this.configureTabsForViewport(event.matches);
            this.mobileQuery.addEventListener('change', this.mobileQueryHandler);
            this.configureTabsForViewport(this.mobileQuery.matches);
        },
        initSwiper() {
            if (!this.mobileTabs || !window.Swiper || !this.$refs.carousel) return;

            this.swiper?.destroy(true, true);
            const controller = this;

            this.swiper = new window.Swiper(this.$refs.carousel, {
                modules: window.SwiperFreeMode ? [window.SwiperFreeMode] : [],
                slidesPerView: 'auto',
                spaceBetween: 5,
                initialSlide: this.activeIndex(),
                centeredSlides: true,
                centeredSlidesBounds: true,
                slideToClickedSlide: true,
                freeMode: {
                    enabled: true,
                    // Ein kurzer Wisch soll den sichtbaren Nachbarbereich
                    // bewegen und nicht durch die komplette Navigation fliegen.
                    // Ein kleines Momentum ist noetig, damit Swiper den aktiven
                    // Index bereits waehrend der Geste fortschreibt.
                    momentum: true,
                    momentumRatio: 0.18,
                    momentumVelocityRatio: 0.35,
                    sticky: true,
                },
                threshold: 5,
                touchAngle: 42,
                touchStartPreventDefault: false,
                touchMoveStopPropagation: false,
                // Buttons bleiben echte Buttons: Swiper darf normale Taps und
                // Tastatur-Clicks nicht abfangen. Das Drag-Threshold trennt die
                // horizontale Geste bereits von einem Tap.
                preventClicks: false,
                preventClicksPropagation: false,
                watchOverflow: true,
                centerInsufficientSlides: true,
                watchSlidesProgress: true,
                observer: true,
                observeParents: true,
                resistanceRatio: 0.72,
                roundLengths: true,
                on: {
                    init() {
                        controller.syncScrollEdges();
                    },
                    progress() {
                        controller.syncScrollEdges();
                    },
                    activeIndexChange(swiper) {
                        controller.selectTabFromSwiper(swiper.activeIndex);
                    },
                    resize() {
                        controller.revealActiveTab('auto');
                    },
                    touchStart() {
                        controller.$refs.carousel?.setAttribute('data-swiping', 'pending');
                    },
                    sliderMove() {
                        controller.$refs.carousel?.setAttribute('data-swiping', 'true');
                    },
                    touchEnd(swiper) {
                        controller.$refs.carousel?.setAttribute('data-swiping', 'false');
                        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        const duration = reduceMotion ? 0 : 220;
                        swiper.slideTo(swiper.activeIndex, duration);
                        window.setTimeout(() => {
                            controller.scrubbingTabs = false;
                            controller.syncScrollEdges();
                        }, duration + 24);
                    },
                    transitionEnd() {
                        controller.scrubbingTabs = false;
                        controller.syncScrollEdges();
                    },
                },
            });
        },
        stickyTopOffset() {
            const topbar = document.querySelector('.rt-shell-topbar');
            const topbarBottom = topbar?.getBoundingClientRect().bottom;
            return Math.max(70, Number.isFinite(topbarBottom) ? topbarBottom : 70) + 8;
        },
        keepSelectedPanelVisible() {
            if (!this.stickyEnabled) return;

            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => {
                    const shell = this.$refs.shell;
                    const panel = Array.from(this.$root.querySelectorAll('[role=tabpanel]'))
                        .find(candidate => candidate.id === `panel-${this.openTab}`);
                    if (!shell || !panel) return;

                    const topOffset = this.stickyTopOffset();
                    const shellRect = shell.getBoundingClientRect();
                    const panelRect = panel.getBoundingClientRect();
                    const anchored = Math.abs(shellRect.top - topOffset) <= 12;
                    const visiblePanelHeight = Math.max(
                        0,
                        Math.min(panelRect.bottom, window.innerHeight) - Math.max(panelRect.top, shellRect.bottom),
                    );
                    const usefulContentVisible = visiblePanelHeight >= Math.min(180, window.innerHeight * 0.28);

                    // Bei bereits angehefteter Rail nie erneut an den Seitenanfang
                    // springen. Nur wenn der neue Inhalt praktisch ausserhalb des
                    // Viewports liegt, wird die Rail exakt unter die Topbar gesetzt.
                    if (anchored || usefulContentVisible) return;

                    const target = Math.max(0, window.scrollY + shellRect.top - topOffset);
                    if (Math.abs(target - window.scrollY) < 8) return;

                    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches
                        ? 'auto'
                        : 'smooth';
                    window.scrollTo({ top: target, behavior });
                });
            });
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
                { scaleY: 0.42, autoAlpha: 0.45 },
                { scaleY: 1, autoAlpha: 1, duration: 0.34, ease: 'power3.out', clearProps: 'transform,opacity,visibility' },
            );
        },
        syncScrollEdges() {
            window.cancelAnimationFrame(this.scrollFrame || 0);
            this.scrollFrame = window.requestAnimationFrame(() => {
                if (!this.swiper) return;
                this.atScrollStart = this.swiper.isBeginning;
                this.atScrollEnd = this.swiper.isEnd;
            });
        },
        destroy() {
            window.cancelAnimationFrame(this.scrollFrame || 0);
            if (this.mobileQuery && this.mobileQueryHandler) {
                this.mobileQuery.removeEventListener('change', this.mobileQueryHandler);
            }
            this.mobileQuery = null;
            this.mobileQueryHandler = null;
            this.swiper?.destroy(true, true);
            this.swiper = null;

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
            initResponsiveTabs();
            syncScrollEdges();
        });
    "
    :data-tab-direction="tabDirection"
    :data-tabs-mobile="mobileTabs ? 'true' : 'false'"
    :data-tabs-scrubbing="scrubbingTabs ? 'true' : 'false'"
    class="w-full min-w-0"
    :data-tabs-input-policy="mobileTabs ? 'swiper-touch' : 'click-only'"
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        x-ref="shell"
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
            class="rt-tabs-carousel swiper"
            data-tab-carousel
            data-slider-library="swiper"
        >
            <div class="rt-tabs-carousel-track swiper-wrapper">
                <template x-for="tab in items" :key="tab.id">
                    <button
                        type="button"
                        @click="selectTab(tab.id, true)"
                        :data-active="openTab === tab.id ? 'true' : 'false'"
                        :data-position="openTab === tab.id ? 'active' : 'inactive'"
                        class="rt-carousel-tab swiper-slide group"
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
