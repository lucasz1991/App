const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * Coupled RailTime tabs for Blade + Alpine.
 *
 * Desktop remains click/keyboard driven. Mobile uses one canonical fractional
 * position for both rails. Navigation and panel gestures write that position
 * in the same animation frame, preventing the visual rail, `openTab` and the
 * rendered panel from drifting apart.
 */
export function railtimeTabs(config = {}) {
    return {
        openTab: config.persistedTab ?? config.initial ?? config.items?.[0]?.id ?? null,
        tabDirection: 'next',
        stickyEnabled: true,
        mobileTabs: false,
        scrubbingTabs: false,
        programmaticNavigation: false,
        modalWasOpen: false,
        panelPosition: 0,
        panelHeight: 0,
        atScrollStart: true,
        atScrollEnd: true,
        items: Array.isArray(config.items) ? config.items : [],
        loadedTabs: [],
        loadingTabs: [],

        mobileQuery: null,
        mobileQueryHandler: null,
        renderFrame: null,
        settleFrame: null,
        resizeFrame: null,
        preloadTimer: null,
        wheelTimer: null,
        panelResizeObserver: null,
        navigationResizeObserver: null,
        panelMutationObserver: null,

        gesturePointerId: null,
        gestureSource: null,
        gestureTarget: null,
        gestureStartX: 0,
        gestureStartY: 0,
        gestureStartPosition: 0,
        gestureStartNavigationOffset: 0,
        gestureDistance: 0,
        gestureLastPosition: 0,
        gestureLastTime: 0,
        gestureVelocity: 0,
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
                this.syncPanelOrder();
                this.initResponsiveTabs();
                this.observePanelSizes();
                this.observeNavigationSize();
                this.observePanelChanges();
                this.renderCoupledPosition(this.panelPosition);
                this.updatePanelHeight(false);
                this.queueAdjacentPreload();
            });
        },

        ensureActiveTab() {
            if (!this.items.some((item) => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },

        resetToInitial() {
            const initialIndex = Math.max(
                0,
                this.items.findIndex((item) => item.id === config.initial),
            );
            const initial = this.items[initialIndex];
            if (!initial) return;

            this.cancelSettleAnimation();
            this.openTab = initial.id;
            this.panelPosition = initialIndex;
            this.scrubbingTabs = false;
            this.programmaticNavigation = false;
            this.loadTab(initial.id, true);

            this.$nextTick(() => {
                this.renderCoupledPosition(initialIndex, true);
                this.updatePanelHeight(false);
                this.syncScrollEdges();
            });
        },

        syncModalOpenState(isOpen) {
            const nextOpen = Boolean(isOpen);

            if (nextOpen && !this.modalWasOpen && config.resetOnOpen) {
                this.resetToInitial();
            }

            this.modalWasOpen = nextOpen;
        },

        activeIndex() {
            return Math.max(0, this.items.findIndex((item) => item.id === this.openTab));
        },

        tabElement(id) {
            return Array.from(this.$refs.carousel?.querySelectorAll('[role=tab]') ?? [])
                .find((tab) => tab.dataset.tabId === id);
        },

        panelElement(id) {
            return Array.from(this.$refs.panelTrack?.querySelectorAll('[role=tabpanel]') ?? [])
                .find((panel) => panel.dataset.tabPanelId === id) ?? null;
        },

        syncPanelOrder() {
            const panels = Array.from(this.$refs.panelTrack?.querySelectorAll('[role=tabpanel]') ?? []);
            const orderById = new Map(this.items.map((item, index) => [item.id, index]));

            panels.forEach((panel) => {
                const index = orderById.get(panel.dataset.tabPanelId);
                if (typeof index !== 'number') return;

                panel.style.order = String(index);
                panel.dataset.tabIndex = String(index);
                this.panelResizeObserver?.observe(panel);
            });
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
                    this.updatePanelHeight(!this.scrubbingTabs);
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

            // Two paints keep the skeleton visible before the lazy panel is
            // revealed. No timer is tied to the gesture/rendering path.
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

        commitActiveIndex(index, duringGesture = false) {
            const nextIndex = clamp(index, 0, Math.max(0, this.items.length - 1));
            const next = this.items[nextIndex];
            if (!next || next.id === this.openTab) return;

            const currentIndex = this.activeIndex();
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = next.id;
            this.loadTab(next.id);

            this.$nextTick(() => {
                this.updatePanelHeight(!duringGesture);
                if (!duringGesture) this.animateSelection();
            });
        },

        selectTab(id, focusTab = false, revealTab = true) {
            const nextIndex = this.items.findIndex((item) => item.id === id);
            if (nextIndex < 0) return;

            if (this.mobileTabs) {
                this.settleToIndex(nextIndex, {
                    focusTab,
                    keepVisible: revealTab,
                });
                return;
            }

            const currentIndex = this.activeIndex();
            this.tabDirection = nextIndex >= currentIndex ? 'next' : 'previous';
            this.openTab = id;
            this.panelPosition = nextIndex;
            this.scrubbingTabs = false;
            this.loadTab(id);

            this.$nextTick(() => {
                this.updatePanelHeight(true);
                this.animateSelection();
                this.queueAdjacentPreload();
                if (revealTab) this.keepSelectedPanelVisible();

                if (focusTab) {
                    this.tabElement(id)?.focus({ preventScroll: true });
                }
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
            // Dialogs disable only sticky positioning, never mobile gestures.
            this.cancelSettleAnimation();
            this.mobileTabs = mobile;
            this.scrubbingTabs = false;
            this.programmaticNavigation = false;
            this.panelPosition = this.activeIndex();

            this.$nextTick(() => {
                this.syncPanelOrder();

                if (this.mobileTabs) {
                    this.renderCoupledPosition(this.panelPosition);
                } else {
                    if (this.$refs.carousel) {
                        this.$refs.carousel.scrollLeft = 0;
                    }
                    this.$refs.carouselTrack?.style.removeProperty('transform');
                    this.$refs.panelTrack?.style.removeProperty('transform');
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
            const track = this.$refs.carouselTrack;
            const tabs = Array.from(track?.querySelectorAll('[role=tab]') ?? []);
            if (!carousel || !track || !tabs.length) return [];

            const maxOffset = Math.max(0, track.scrollWidth - carousel.clientWidth);

            return tabs.map((tab, index) => {
                if (index === 0) return 0;
                if (index === tabs.length - 1) return maxOffset;

                const centered = tab.offsetLeft + (tab.offsetWidth / 2) - (carousel.clientWidth / 2);
                return clamp(centered, 0, maxOffset);
            });
        },

        navigationOffsetForPosition(position) {
            const stops = this.navigationStops();
            if (!stops.length) return 0;

            const bounded = clamp(position, 0, stops.length - 1);
            const lower = Math.floor(bounded);
            const upper = Math.min(stops.length - 1, Math.ceil(bounded));
            const progress = bounded - lower;

            return stops[lower] + ((stops[upper] - stops[lower]) * progress);
        },

        navigationGestureStops() {
            const track = this.$refs.carouselTrack;
            const tabs = Array.from(track?.querySelectorAll('[role=tab]') ?? []);
            if (!tabs.length) return [];

            // Die sichtbaren Center-Stops werden an den beiden Raendern
            // geklemmt. Dadurch koennen z. B. Tab 1 und 2 beide den Offset 0
            // besitzen. Fuer die Gestenabbildung waere das mehrdeutig und ein
            // kleiner Swipe koennte direkt einen Index ueberspringen. Die
            // ungeklemmten Tab-Mittelpunkte bleiben dagegen streng geordnet.
            const centers = tabs.map((tab) => tab.offsetLeft + (tab.offsetWidth / 2));
            const firstCenter = centers[0];

            return centers.map((center, index) => (
                index === 0 ? 0 : Math.max(index, center - firstCenter)
            ));
        },

        navigationGestureOffsetForPosition(position) {
            const stops = this.navigationGestureStops();
            if (!stops.length) return 0;

            const bounded = clamp(position, 0, stops.length - 1);
            const lower = Math.floor(bounded);
            const upper = Math.min(stops.length - 1, Math.ceil(bounded));
            const progress = bounded - lower;

            return stops[lower] + ((stops[upper] - stops[lower]) * progress);
        },

        positionForNavigationGestureOffset(offset) {
            const stops = this.navigationGestureStops();
            if (stops.length < 2) return this.panelPosition;

            const bounded = clamp(offset, 0, stops.at(-1));

            for (let index = 0; index < stops.length - 1; index += 1) {
                const start = stops[index];
                const end = stops[index + 1];
                if (bounded > end && index < stops.length - 2) continue;

                return index + clamp((bounded - start) / Math.max(1, end - start), 0, 1);
            }

            return stops.length - 1;
        },

        setCoupledPosition(position, commit = true, immediate = false) {
            const bounded = clamp(position, 0, Math.max(0, this.items.length - 1));
            this.panelPosition = bounded;
            this.renderCoupledPosition(bounded, immediate);

            if (commit) {
                this.commitActiveIndex(Math.round(bounded), true);
            }
        },

        applyCoupledTransforms(position) {
            if (!this.mobileTabs) return;

            // Der gekoppelte Transform ist mobil die einzige Positionsquelle.
            // Browser duerfen beim Fokussieren eines teilweise verdeckten
            // Buttons den overflow-hidden Container trotzdem intern scrollen;
            // ein solcher Restwert wuerde jeden mittleren Stopp verschieben.
            if (this.$refs.carousel?.scrollLeft) {
                this.$refs.carousel.scrollLeft = 0;
            }

            const navigationOffset = this.navigationOffsetForPosition(position);

            if (this.$refs.carouselTrack) {
                this.$refs.carouselTrack.style.transform = `translate3d(${-navigationOffset}px, 0, 0)`;
            }

            if (this.$refs.panelTrack) {
                this.$refs.panelTrack.style.transform = `translate3d(${-100 * position}%, 0, 0)`;
            }

            this.syncScrollEdges(navigationOffset);
        },

        renderCoupledPosition(position, immediate = false) {
            if (!this.mobileTabs) return;

            window.cancelAnimationFrame(this.renderFrame || 0);
            if (immediate) {
                this.renderFrame = null;
                this.applyCoupledTransforms(position);
                return;
            }

            this.renderFrame = window.requestAnimationFrame(() => {
                this.renderFrame = null;
                this.applyCoupledTransforms(position);
            });
        },

        panelViewportStyle() {
            if (!this.mobileTabs || !this.panelHeight) return '';
            return `height: ${this.panelHeight}px;`;
        },

        isInteractiveGestureTarget(target) {
            return target instanceof Element
                && Boolean(target.closest(
                    'a, button, input, textarea, select, option, [role=button], [contenteditable=true], [data-no-tab-swipe]',
                ));
        },

        beginCoupledDrag(event, source) {
            if (!this.mobileTabs || !event.isPrimary) return;
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            if (source === 'content' && this.isInteractiveGestureTarget(event.target)) return;

            this.cancelSettleAnimation();
            this.gesturePointerId = event.pointerId;
            this.gestureSource = source;
            this.gestureTarget = event.currentTarget;
            this.gestureStartX = event.clientX;
            this.gestureStartY = event.clientY;
            this.gestureStartPosition = this.panelPosition;
            this.gestureStartNavigationOffset = this.navigationGestureOffsetForPosition(this.panelPosition);
            this.gestureDistance = 0;
            this.gestureLastPosition = this.panelPosition;
            this.gestureLastTime = performance.now();
            this.gestureVelocity = 0;
            this.suppressClick = false;

            event.currentTarget.setPointerCapture?.(event.pointerId);
        },

        moveCoupledDrag(event) {
            if (event.pointerId !== this.gesturePointerId) return;

            const distanceX = event.clientX - this.gestureStartX;
            const distanceY = event.clientY - this.gestureStartY;
            this.gestureDistance = Math.max(this.gestureDistance, Math.abs(distanceX));

            if (this.gestureDistance < 4) return;
            if (Math.abs(distanceY) > Math.abs(distanceX) * 1.2) return;

            event.preventDefault();
            if (
                !this.scrubbingTabs
                && document.activeElement instanceof HTMLElement
                && this.$refs.carousel?.contains(document.activeElement)
            ) {
                // Ein Swipe darf den berührten, aber nicht ausgewählten Tab
                // nicht mit einem Focus-Ring wie einen zweiten aktiven Tab
                // aussehen lassen. Tastaturfokus bleibt davon unberührt.
                document.activeElement.blur();
            }
            this.scrubbingTabs = true;
            this.suppressClick = true;
            this.$refs.carousel?.setAttribute('data-swiping', 'true');
            this.$refs.panels?.setAttribute('data-swiping', 'true');

            let nextPosition;
            if (this.gestureSource === 'navigation') {
                const stops = this.navigationGestureStops();
                if ((stops.at(-1) ?? 0) <= 0) {
                    const width = Math.max(1, this.$refs.carousel?.clientWidth ?? 1);
                    nextPosition = this.gestureStartPosition - (distanceX / (width * 0.72));
                } else {
                    nextPosition = this.positionForNavigationGestureOffset(
                        this.gestureStartNavigationOffset - distanceX,
                    );
                }
            } else {
                const width = Math.max(1, this.$refs.panels?.clientWidth ?? 1);
                nextPosition = this.gestureStartPosition - (distanceX / width);
            }

            const bounded = clamp(nextPosition, 0, Math.max(0, this.items.length - 1));
            const now = performance.now();
            const elapsed = Math.max(1, now - this.gestureLastTime);
            const instantVelocity = (bounded - this.gestureLastPosition) / elapsed;
            this.gestureVelocity = (this.gestureVelocity * 0.68) + (instantVelocity * 0.32);
            this.gestureLastPosition = bounded;
            this.gestureLastTime = now;

            this.setCoupledPosition(bounded);
        },

        endCoupledDrag(event) {
            if (event.pointerId !== this.gesturePointerId) return;

            if (this.gestureTarget?.hasPointerCapture?.(event.pointerId)) {
                this.gestureTarget.releasePointerCapture(event.pointerId);
            }
            this.$refs.carousel?.setAttribute('data-swiping', 'false');
            this.$refs.panels?.setAttribute('data-swiping', 'false');

            const moved = this.gestureDistance >= 4;
            /*
             * Ein sehr schneller Pointer-Event-Block (besonders in Chromium
             * und installierten PWAs) kann eine unrealistisch hohe Momentan-
             * geschwindigkeit liefern. Die reale Dragposition bleibt die
             * Hauptquelle; Momentum darf hoechstens gut ein Drittel eines
             * Tabs addieren. So bleibt freies Mehrfach-Dragging moeglich,
             * ohne dass ein kurzer Swipe bis zum letzten Tab durchschiesst.
             */
            const projectedMomentum = clamp(this.gestureVelocity * 110, -0.35, 0.35);
            const projectedPosition = this.panelPosition + projectedMomentum;
            const targetIndex = moved
                ? Math.round(clamp(projectedPosition, 0, Math.max(0, this.items.length - 1)))
                : Math.round(this.panelPosition);

            this.gesturePointerId = null;
            this.gestureSource = null;
            this.gestureTarget = null;
            this.gestureVelocity = 0;

            if (moved) {
                this.settleToIndex(targetIndex);
            } else {
                this.scrubbingTabs = false;
            }

            window.setTimeout(() => {
                this.suppressClick = false;
            }, 0);
        },

        onNavigationWheel(event) {
            if (!this.mobileTabs) return;

            const delta = Math.abs(event.deltaX) >= Math.abs(event.deltaY)
                ? event.deltaX
                : (event.shiftKey ? event.deltaY : 0);
            if (!delta) return;

            event.preventDefault();
            this.cancelSettleAnimation();
            this.scrubbingTabs = true;

            const stops = this.navigationGestureStops();
            const currentOffset = this.navigationGestureOffsetForPosition(this.panelPosition);
            const nextPosition = (stops.at(-1) ?? 0) > 0
                ? this.positionForNavigationGestureOffset(currentOffset + delta)
                : this.panelPosition + (delta / Math.max(1, this.$refs.carousel?.clientWidth ?? 1));

            this.setCoupledPosition(nextPosition);

            window.clearTimeout(this.wheelTimer);
            this.wheelTimer = window.setTimeout(() => {
                this.settleToIndex(Math.round(this.panelPosition));
            }, 90);
        },

        cancelSettleAnimation() {
            window.cancelAnimationFrame(this.settleFrame || 0);
            this.settleFrame = null;
            this.programmaticNavigation = false;
        },

        settleToIndex(index, options = {}) {
            const targetIndex = clamp(index, 0, Math.max(0, this.items.length - 1));
            const target = this.items[targetIndex];
            if (!target) return;

            this.cancelSettleAnimation();
            this.scrubbingTabs = true;
            this.programmaticNavigation = true;
            this.loadTab(target.id);

            const startPosition = this.panelPosition;
            const distance = Math.abs(targetIndex - startPosition);
            const duration = this.reducedMotion()
                ? 0
                : clamp(190 + (distance * 105), 190, 360);

            const complete = () => {
                this.panelPosition = targetIndex;
                this.renderCoupledPosition(targetIndex, true);
                this.commitActiveIndex(targetIndex, false);
                this.scrubbingTabs = false;
                this.programmaticNavigation = false;
                this.updatePanelHeight(true);
                this.animateSelection();
                this.queueAdjacentPreload();

                if (options.keepVisible !== false) this.keepSelectedPanelVisible();
                if (options.focusTab) {
                    this.tabElement(target.id)?.focus({ preventScroll: true });
                }
            };

            if (!duration || Math.abs(targetIndex - startPosition) < 0.001) {
                complete();
                return;
            }

            const startedAt = performance.now();
            const animate = (now) => {
                const progress = clamp((now - startedAt) / duration, 0, 1);
                const eased = 1 - ((1 - progress) ** 3);
                this.setCoupledPosition(
                    startPosition + ((targetIndex - startPosition) * eased),
                    true,
                    true,
                );

                if (progress < 1) {
                    this.settleFrame = window.requestAnimationFrame(animate);
                } else {
                    this.settleFrame = null;
                    complete();
                }
            };

            this.settleFrame = window.requestAnimationFrame(animate);
        },

        updatePanelHeight(animate = true) {
            window.cancelAnimationFrame(this.resizeFrame || 0);
            this.resizeFrame = window.requestAnimationFrame(() => {
                const active = this.panelElement(this.openTab);
                if (!active) return;

                const nextHeight = Math.ceil(active.scrollHeight);
                if (!nextHeight) return;

                if (!animate || this.reducedMotion() || this.scrubbingTabs) {
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
                if (entries.some((entry) => entry.target.dataset.tabPanelId === this.openTab)) {
                    this.updatePanelHeight(false);
                }
            });

            this.$refs.panelTrack.querySelectorAll('[role=tabpanel]')
                .forEach((panel) => this.panelResizeObserver.observe(panel));
        },

        observeNavigationSize() {
            if (!window.ResizeObserver || !this.$refs.carousel || !this.$refs.carouselTrack) return;

            this.navigationResizeObserver = new ResizeObserver(() => {
                if (!this.mobileTabs) return;
                this.renderCoupledPosition(this.panelPosition);
            });
            this.navigationResizeObserver.observe(this.$refs.carousel);
            this.navigationResizeObserver.observe(this.$refs.carouselTrack);
        },

        observePanelChanges() {
            if (!window.MutationObserver || !this.$refs.panelTrack) return;

            this.panelMutationObserver = new MutationObserver(() => {
                this.syncPanelOrder();
                this.renderCoupledPosition(this.panelPosition);
                this.updatePanelHeight(false);
            });
            this.panelMutationObserver.observe(this.$refs.panelTrack, {
                childList: true,
            });
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
                const viewportBottom = window.visualViewport
                    ? window.visualViewport.offsetTop + window.visualViewport.height
                    : window.innerHeight;
                const panelFullyVisible = panelRect.top >= shellRect.bottom - 1
                    && panelRect.bottom <= viewportBottom - 8;

                if (
                    Math.abs(shellRect.top - topOffset) <= 12
                    || panelFullyVisible
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
            if (!window.gsap || this.reducedMotion() || this.scrubbingTabs) return;

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

        syncScrollEdges(offset = this.navigationOffsetForPosition(this.panelPosition)) {
            const stops = this.navigationStops();
            const maxOffset = stops.at(-1) ?? 0;
            this.atScrollStart = offset <= 1;
            this.atScrollEnd = offset >= maxOffset - 1;
        },

        reducedMotion() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        destroy() {
            window.cancelAnimationFrame(this.renderFrame || 0);
            window.cancelAnimationFrame(this.settleFrame || 0);
            window.cancelAnimationFrame(this.resizeFrame || 0);
            window.clearTimeout(this.preloadTimer);
            window.clearTimeout(this.wheelTimer);

            if (this.mobileQuery && this.mobileQueryHandler) {
                this.mobileQuery.removeEventListener('change', this.mobileQueryHandler);
            }

            this.panelResizeObserver?.disconnect();
            this.navigationResizeObserver?.disconnect();
            this.panelMutationObserver?.disconnect();

            if (window.gsap) {
                window.gsap.killTweensOf(
                    this.$root.querySelectorAll('.rt-carousel-tab, [data-rt-tab-active-mark], [data-rt-tab-content]'),
                );
            }
        },
    };
}
