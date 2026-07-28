const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * Coupled RailTime tabs for Blade + Alpine.
 *
 * Desktop stays click/keyboard driven. On mobile the navigation is a native,
 * momentum-enabled horizontal scroller. Its fractional scroll position drives
 * the content track directly, so navigation and content move as one gesture.
 */
export function railtimeTabs(config = {}) {
    return {
        openTab: config.persistedTab ?? config.initial ?? config.items?.[0]?.id ?? null,
        tabDirection: 'next',
        stickyEnabled: true,
        mobileTabs: false,
        scrubbingTabs: false,
        programmaticNavigation: false,
        panelPosition: 0,
        panelHeight: 0,
        atScrollStart: true,
        atScrollEnd: true,
        items: Array.isArray(config.items) ? config.items : [],
        loadedTabs: [],
        loadingTabs: [],
        mobileQuery: null,
        mobileQueryHandler: null,
        scrollFrame: null,
        resizeFrame: null,
        scrollEndTimer: null,
        programmaticTimer: null,
        preloadTimer: null,
        panelResizeObserver: null,
        dragPointerId: null,
        dragStartX: 0,
        dragStartScrollLeft: 0,
        dragDistance: 0,
        suppressClick: false,

        init() {
            if (config.forceDefault) {
                this.openTab = config.initial;
            }

            this.ensureActiveTab();
            this.panelPosition = this.activeIndex();
            this.loadedTabs = [this.openTab];
            this.stickyEnabled = !this.$root.closest('[role=dialog]');

            this.$nextTick(() => {
                this.initResponsiveTabs();
                this.observePanelSizes();
                this.syncScrollEdges();
                this.updatePanelHeight(false);
                this.queueAdjacentPreload();
            });
        },

        ensureActiveTab() {
            if (!this.items.some((item) => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },

        activeIndex() {
            return Math.max(0, this.items.findIndex((item) => item.id === this.openTab));
        },

        tabElement(id) {
            return Array.from(this.$refs.carousel?.querySelectorAll('[role=tab]') ?? [])
                .find((tab) => tab.dataset.tabId === id);
        },

        panelElement(id) {
            return this.$refs.panelTrack?.querySelector(`#panel-${CSS.escape(id)}`) ?? null;
        },

        isTabLoaded(id) {
            return this.loadedTabs.includes(id);
        },

        isTabLoading(id) {
            return this.loadingTabs.includes(id);
        },

        loadTab(id, immediate = false) {
            if (!id || this.isTabLoaded(id) || this.isTabLoading(id)) return;

            this.loadingTabs = [...this.loadingTabs, id];
            this.$dispatch('rt-tab:loading', { id });

            const reveal = () => {
                this.loadingTabs = this.loadingTabs.filter((item) => item !== id);
                this.loadedTabs = [...this.loadedTabs, id];

                this.$nextTick(() => {
                    this.updatePanelHeight(true);
                    this.animateLoadedPanel(id);
                    this.$dispatch('rt-tab:loaded', { id });
                });
            };

            if (immediate || this.reducedMotion()) {
                reveal();
                window.dispatchEvent(new CustomEvent('rt-tab:preload', {
                    detail: { id },
                }));
                return;
            }

            // Two paint frames make the lightweight skeleton visible
            // immediately while the already-rendered Livewire panel activates.
            window.requestAnimationFrame(() => window.requestAnimationFrame(reveal));
        },

        queueAdjacentPreload() {
            window.clearTimeout(this.preloadTimer);
            this.preloadTimer = window.setTimeout(() => {
                const index = this.activeIndex();
                [index - 1, index + 1]
                    .map((candidate) => this.items[candidate]?.id)
                    .filter(Boolean)
                    .forEach((id) => this.loadTab(id, true));
            }, 1200);
        },

        selectTab(id, focusTab = false, revealTab = true) {
            const nextIndex = this.items.findIndex((item) => item.id === id);
            if (nextIndex < 0) return;

            const currentIndex = this.activeIndex();
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = id;
            this.panelPosition = nextIndex;
            this.scrubbingTabs = false;
            this.loadTab(id);

            this.$nextTick(() => {
                if (revealTab) this.revealActiveTab();
                this.updatePanelHeight(true);
                this.animateSelection();
                this.queueAdjacentPreload();
                this.keepSelectedPanelVisible();

                if (focusTab) {
                    this.tabElement(id)?.focus({ preventScroll: true });
                }
            });
        },

        selectIndexFromNavigation(position) {
            const nextIndex = clamp(Math.round(position), 0, Math.max(0, this.items.length - 1));
            const next = this.items[nextIndex];
            if (!next || next.id === this.openTab) return;

            const currentIndex = this.activeIndex();
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = next.id;
            this.loadTab(next.id);

            this.$nextTick(() => {
                this.updatePanelHeight(true);
                this.animateSelection();
                this.queueAdjacentPreload();
            });
        },

        moveTab(direction) {
            if (this.items.length < 2) return;

            const nextIndex = (this.activeIndex() + direction + this.items.length) % this.items.length;
            this.selectTab(this.items[nextIndex].id, true);
        },

        moveToBoundary(position) {
            if (!this.items.length) return;
            this.selectTab(position === 'start' ? this.items[0].id : this.items.at(-1).id, true);
        },

        configureTabsForViewport(mobile) {
            // Dialogs disable only sticky positioning, never the mobile
            // interaction itself. Tabs in modals must remain draggable too.
            this.mobileTabs = mobile;
            this.scrubbingTabs = false;
            this.programmaticNavigation = false;
            this.panelPosition = this.activeIndex();

            this.$nextTick(() => {
                if (this.mobileTabs) {
                    this.revealActiveTab('auto');
                } else if (this.$refs.carousel) {
                    this.$refs.carousel.scrollLeft = 0;
                }

                this.updatePanelHeight(false);
                this.syncScrollEdges();
            });
        },

        initResponsiveTabs() {
            this.mobileQuery = window.matchMedia('(max-width: 767.98px)');
            this.mobileQueryHandler = (event) => this.configureTabsForViewport(event.matches);
            this.mobileQuery.addEventListener('change', this.mobileQueryHandler);
            this.configureTabsForViewport(this.mobileQuery.matches);
        },

        navigationStops() {
            const carousel = this.$refs.carousel;
            const tabs = Array.from(carousel?.querySelectorAll('[role=tab]') ?? []);
            if (!carousel || !tabs.length) return [];

            const maxScroll = Math.max(0, carousel.scrollWidth - carousel.clientWidth);

            return tabs.map((tab, index) => {
                if (index === 0) return 0;
                if (index === tabs.length - 1) return maxScroll;

                const centered = tab.offsetLeft + (tab.offsetWidth / 2) - (carousel.clientWidth / 2);
                return clamp(centered, 0, maxScroll);
            });
        },

        navigationPosition() {
            const carousel = this.$refs.carousel;
            const stops = this.navigationStops();
            if (!carousel || stops.length < 2) return 0;

            const current = clamp(carousel.scrollLeft, 0, stops.at(-1));

            for (let index = 0; index < stops.length - 1; index += 1) {
                const start = stops[index];
                const end = stops[index + 1];
                if (current > end && index < stops.length - 2) continue;
                if (end <= start) return index + 1;

                return index + clamp((current - start) / (end - start), 0, 1);
            }

            return stops.length - 1;
        },

        onNavigationScroll() {
            this.syncScrollEdges();
            if (!this.mobileTabs || this.programmaticNavigation) return;

            window.cancelAnimationFrame(this.scrollFrame || 0);
            this.scrollFrame = window.requestAnimationFrame(() => {
                const position = this.navigationPosition();
                this.scrubbingTabs = true;
                this.panelPosition = position;
                this.selectIndexFromNavigation(position);
            });

            window.clearTimeout(this.scrollEndTimer);
            this.scrollEndTimer = window.setTimeout(() => this.finishNavigationScrub(), 110);
        },

        finishNavigationScrub() {
            if (!this.mobileTabs || this.programmaticNavigation) return;

            this.scrubbingTabs = false;
            this.panelPosition = this.activeIndex();
            this.updatePanelHeight(true);
        },

        revealActiveTab(behavior = 'smooth') {
            if (!this.mobileTabs || !this.$refs.carousel) return;

            const stops = this.navigationStops();
            const target = stops[this.activeIndex()] ?? 0;
            const reduceMotion = this.reducedMotion();

            this.programmaticNavigation = true;
            this.$refs.carousel.scrollTo({
                left: target,
                behavior: reduceMotion || behavior === 'auto' ? 'auto' : 'smooth',
            });

            window.clearTimeout(this.programmaticTimer);
            this.programmaticTimer = window.setTimeout(() => {
                this.programmaticNavigation = false;
                this.syncScrollEdges();
            }, reduceMotion || behavior === 'auto' ? 0 : 420);
        },

        beginPointerDrag(event) {
            if (!this.mobileTabs || event.pointerType === 'touch' || event.button !== 0) return;

            this.dragPointerId = event.pointerId;
            this.dragStartX = event.clientX;
            this.dragStartScrollLeft = this.$refs.carousel.scrollLeft;
            this.dragDistance = 0;
            this.suppressClick = false;
            this.$refs.carousel.setPointerCapture?.(event.pointerId);
        },

        movePointerDrag(event) {
            if (event.pointerId !== this.dragPointerId) return;

            const distance = event.clientX - this.dragStartX;
            this.dragDistance = Math.max(this.dragDistance, Math.abs(distance));
            if (this.dragDistance < 4) return;

            event.preventDefault();
            this.suppressClick = true;
            this.scrubbingTabs = true;
            this.$refs.carousel.dataset.swiping = 'true';
            this.$refs.carousel.scrollLeft = this.dragStartScrollLeft - distance;
        },

        endPointerDrag(event) {
            if (event.pointerId !== this.dragPointerId) return;

            this.$refs.carousel.releasePointerCapture?.(event.pointerId);
            this.$refs.carousel.dataset.swiping = 'false';
            this.dragPointerId = null;
            this.finishNavigationScrub();

            window.setTimeout(() => {
                this.suppressClick = false;
            }, 0);
        },

        beginTouchScrub() {
            if (!this.mobileTabs) return;
            this.scrubbingTabs = true;
            this.$refs.carousel.dataset.swiping = 'true';
        },

        endTouchScrub() {
            if (!this.mobileTabs) return;
            this.$refs.carousel.dataset.swiping = 'false';
            window.clearTimeout(this.scrollEndTimer);
            this.scrollEndTimer = window.setTimeout(() => this.finishNavigationScrub(), 110);
        },

        panelTrackStyle() {
            if (!this.mobileTabs) return '';
            return `transform: translate3d(${-100 * this.panelPosition}%, 0, 0);`;
        },

        panelViewportStyle() {
            if (!this.mobileTabs || !this.panelHeight) return '';
            return `height: ${this.panelHeight}px;`;
        },

        updatePanelHeight(animate = true) {
            window.cancelAnimationFrame(this.resizeFrame || 0);
            this.resizeFrame = window.requestAnimationFrame(() => {
                const active = this.panelElement(this.openTab);
                if (!active) return;

                const nextHeight = Math.ceil(active.scrollHeight);
                if (!nextHeight) return;

                if (!animate || this.reducedMotion()) {
                    this.$refs.panels?.setAttribute('data-resizing', 'false');
                } else {
                    this.$refs.panels?.setAttribute('data-resizing', 'true');
                    window.setTimeout(() => this.$refs.panels?.setAttribute('data-resizing', 'false'), 360);
                }

                this.panelHeight = nextHeight;
            });
        },

        observePanelSizes() {
            if (!window.ResizeObserver || !this.$refs.panelTrack) return;

            this.panelResizeObserver = new ResizeObserver((entries) => {
                if (entries.some((entry) => entry.target.id === `panel-${this.openTab}`)) {
                    this.updatePanelHeight(false);
                }
            });

            this.$refs.panelTrack.querySelectorAll('[role=tabpanel]')
                .forEach((panel) => this.panelResizeObserver.observe(panel));
        },

        keepSelectedPanelVisible() {
            if (!this.stickyEnabled) return;

            window.requestAnimationFrame(() => {
                const shell = this.$refs.shell;
                const panel = this.panelElement(this.openTab);
                if (!shell || !panel) return;

                const topbar = document.querySelector('.rt-shell-topbar');
                const topbarBottom = topbar?.getBoundingClientRect().bottom;
                const topOffset = Math.max(70, Number.isFinite(topbarBottom) ? topbarBottom : 70) + 8;
                const shellRect = shell.getBoundingClientRect();
                const panelRect = panel.getBoundingClientRect();
                const visibleHeight = Math.max(
                    0,
                    Math.min(panelRect.bottom, window.innerHeight) - Math.max(panelRect.top, shellRect.bottom),
                );

                if (
                    Math.abs(shellRect.top - topOffset) <= 12
                    || visibleHeight >= Math.min(180, window.innerHeight * 0.28)
                ) return;

                const target = Math.max(0, window.scrollY + shellRect.top - topOffset);
                if (Math.abs(target - window.scrollY) < 8) return;

                window.scrollTo({
                    top: target,
                    behavior: this.reducedMotion() ? 'auto' : 'smooth',
                });
            });
        },

        animateSelection() {
            if (!window.gsap || this.reducedMotion()) return;

            const active = this.tabElement(this.openTab);
            const marker = active?.querySelector('[data-rt-tab-active-mark]');
            if (!active || !marker) return;

            window.gsap.killTweensOf([active, marker]);
            window.gsap.fromTo(
                active,
                { scale: 0.985 },
                { scale: 1, duration: 0.24, ease: 'power2.out', overwrite: 'auto', clearProps: 'transform' },
            );
            window.gsap.fromTo(
                marker,
                { scaleY: 0.42, autoAlpha: 0.45 },
                {
                    scaleY: 1,
                    autoAlpha: 1,
                    duration: 0.34,
                    ease: 'power3.out',
                    overwrite: 'auto',
                    clearProps: 'transform,opacity,visibility',
                },
            );
        },

        animateLoadedPanel(id) {
            if (!window.gsap || this.reducedMotion()) return;

            const content = this.panelElement(id)?.querySelector('[data-rt-tab-content]');
            if (!content) return;

            window.gsap.fromTo(
                content,
                { y: 10, autoAlpha: 0.6 },
                {
                    y: 0,
                    autoAlpha: 1,
                    duration: 0.34,
                    ease: 'power3.out',
                    overwrite: 'auto',
                    clearProps: 'transform,opacity,visibility',
                },
            );
        },

        syncScrollEdges() {
            const carousel = this.$refs.carousel;
            if (!carousel) return;

            const maxScroll = Math.max(0, carousel.scrollWidth - carousel.clientWidth);
            this.atScrollStart = carousel.scrollLeft <= 1;
            this.atScrollEnd = carousel.scrollLeft >= maxScroll - 1;
        },

        reducedMotion() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        destroy() {
            window.cancelAnimationFrame(this.scrollFrame || 0);
            window.cancelAnimationFrame(this.resizeFrame || 0);
            window.clearTimeout(this.scrollEndTimer);
            window.clearTimeout(this.programmaticTimer);
            window.clearTimeout(this.preloadTimer);

            if (this.mobileQuery && this.mobileQueryHandler) {
                this.mobileQuery.removeEventListener('change', this.mobileQueryHandler);
            }

            this.panelResizeObserver?.disconnect();

            if (window.gsap) {
                window.gsap.killTweensOf(
                    this.$root.querySelectorAll('.rt-carousel-tab, [data-rt-tab-active-mark], [data-rt-tab-content]'),
                );
            }
        },
    };
}
