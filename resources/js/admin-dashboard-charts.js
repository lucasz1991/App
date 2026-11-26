const loadAdminDashboardECharts = () => import('./admin-dashboard-echarts');
const loadAdminDashboardMotion = () => import('./admin-dashboard-motion');

export const adminDashboardCharts = (config = {}) => ({
    charts: [],
    kpiMotion: null,
    kpiObserver: null,
    counterTween: null,
    progressTweens: [],
    themeObserver: null,
    resizeObserver: null,
    renderTimer: null,
    renderRequest: null,
    chartsRendered: false,
    motion: null,
    motionRequest: null,

    init() {
        this.$nextTick(() => {
            this.observeKpis();
            this.setupMotion();
            window.requestAnimationFrame(() => this.renderCharts(true));
        });

        this.themeObserver = new MutationObserver(() => {
            window.clearTimeout(this.renderTimer);
            this.renderTimer = window.setTimeout(() => this.renderCharts(!this.chartsRendered), 80);
        });
        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    },

    destroy() {
        this.renderRequest = null;
        this.motionRequest = null;
        window.clearTimeout(this.renderTimer);
        this.themeObserver?.disconnect();
        this.kpiObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.counterTween?.kill();
        this.progressTweens.forEach((tween) => tween.kill());
        this.progressTweens = [];
        this.kpiMotion?.revert();
        this.motion?.destroy();
        this.motion = null;
        this.destroyCharts();
    },

    // DrawSVG-, MotionPath- und Zaehleranimationen liegen in einem eigenen
    // Lazy-Chunk. Er wird nur auf dieser Seite geladen und beim Verlassen
    // (Livewire-Navigation) wieder vollstaendig zurueckgenommen.
    async setupMotion() {
        const request = Symbol('admin-dashboard-motion');
        this.motionRequest = request;

        const { createAdminDashboardMotion } = await loadAdminDashboardMotion();

        if (this.motionRequest !== request || !this.$root.isConnected) return;

        this.motion?.destroy();
        this.motion = createAdminDashboardMotion(this.$root);
    },

    observeKpis() {
        const kpiGrid = this.$root.querySelector('[data-dashboard-kpis]');

        if (!kpiGrid) return;

        const start = () => {
            this.kpiObserver?.disconnect();
            this.kpiObserver = null;
            window.requestAnimationFrame(() => {
                if (this.$root.isConnected) this.animateCounters(kpiGrid);
            });
        };

        if (
            typeof IntersectionObserver === 'undefined'
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            start();
            return;
        }

        this.kpiObserver = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) start();
            },
            { threshold: 0.18, rootMargin: '0px 0px -6% 0px' },
        );
        this.kpiObserver.observe(kpiGrid);
    },

    animateCounters(kpiGrid) {
        const formatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
        const counters = Array.from(kpiGrid.querySelectorAll('[data-dashboard-count]'))
            .map((element) => ({
                element,
                target: Number(element.dataset.dashboardCount),
                rendered: null,
            }))
            .filter((counter) => Number.isFinite(counter.target));
        const meters = Array.from(kpiGrid.querySelectorAll('[data-dashboard-progress]'))
            .map((element) => ({
                element,
                ratio: Math.min(100, Math.max(0, Number(element.dataset.dashboardProgress || 0))) / 100,
            }));

        const renderFinalState = () => {
            counters.forEach((counter) => {
                counter.element.textContent = formatter.format(counter.target);
            });

            meters.forEach(({ element, ratio }) => {
                if (window.gsap) {
                    window.gsap.set(element, { scaleX: ratio, transformOrigin: 'left center' });
                } else {
                    element.style.transform = `scaleX(${ratio})`;
                    element.style.transformOrigin = 'left center';
                }
            });
        };

        this.counterTween?.kill();
        this.progressTweens.forEach((tween) => tween.kill());
        this.progressTweens = [];
        this.kpiMotion?.revert();

        if (!window.gsap) {
            renderFinalState();
            return;
        }

        this.kpiMotion = window.gsap.matchMedia();
        this.kpiMotion.add(
            {
                reduceMotion: '(prefers-reduced-motion: reduce)',
                animateMotion: '(prefers-reduced-motion: no-preference)',
            },
            ({ conditions }) => {
                if (conditions.reduceMotion) {
                    renderFinalState();
                    return;
                }

                counters.forEach((counter) => {
                    counter.rendered = 0;
                    counter.element.textContent = formatter.format(0);
                });

                const state = { progress: 0 };

                this.counterTween = window.gsap.to(state, {
                    progress: 1,
                    duration: 0.9,
                    ease: 'power3.out',
                    overwrite: true,
                    onUpdate: () => {
                        counters.forEach((counter) => {
                            const nextValue = Math.round(counter.target * state.progress);
                            if (nextValue === counter.rendered) return;

                            counter.rendered = nextValue;
                            counter.element.textContent = formatter.format(nextValue);
                        });
                    },
                    onComplete: renderFinalState,
                });

                this.progressTweens = meters.map(({ element, ratio }, index) => window.gsap.fromTo(
                    element,
                    { scaleX: 0, transformOrigin: 'left center' },
                    {
                        scaleX: ratio,
                        duration: 1.05,
                        delay: index * 0.08,
                        ease: 'power3.out',
                        overwrite: 'auto',
                    },
                ));
            },
            this.$root,
        );
    },

    destroyCharts() {
        this.resizeObserver?.disconnect();
        this.charts.forEach((chart) => chart.dispose());
        this.charts = [];
    },

    async renderCharts(animate = !this.chartsRendered) {
        const request = Symbol('admin-dashboard-chart-render');
        this.renderRequest = request;

        const { renderAdminDashboardCharts } = await loadAdminDashboardECharts();

        if (this.renderRequest !== request || !this.$root.isConnected) return;

        this.destroyCharts();

        const rendered = renderAdminDashboardCharts({
            refs: {
                growthChart: this.$refs.growthChart,
                statusChart: this.$refs.statusChart,
                activityChart: this.$refs.activityChart,
            },
            config,
            dark: document.documentElement.classList.contains('dark'),
            animate,
        });

        this.charts = rendered.charts;
        this.resizeObserver = rendered.resizeObserver;
        this.chartsRendered = true;
    },
});
