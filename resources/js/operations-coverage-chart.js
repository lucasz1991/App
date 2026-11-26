const loadOperationsCharts = () => import('./operations-charts');

// Betriebs-Cockpit: ein einzelnes Wochenbesetzungs-Diagramm statt des
// vollen admin-dashboard-echarts-Zustands (Zaehler-Animation, DrawSVG-Motion
// sind fuer eine 60s-Polling-Kachel nicht sinnvoll). Eigener Lazy-Chunk,
// eigener MutationObserver fuer den Theme-Wechsel.
export const operationsCoverageChart = (config = {}) => ({
    chart: null,
    resizeObserver: null,
    themeObserver: null,
    renderRequest: null,

    init() {
        this.$nextTick(() => this.renderChart(true));

        this.themeObserver = new MutationObserver(() => this.renderChart(false));
        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    },

    destroy() {
        this.renderRequest = null;
        this.themeObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.chart?.dispose();
        this.chart = null;
    },

    async renderChart(animate) {
        const request = Symbol('operations-coverage-chart');
        this.renderRequest = request;

        const { renderCoverageChart } = await loadOperationsCharts();

        if (this.renderRequest !== request || !this.$root.isConnected) return;

        this.resizeObserver?.disconnect();
        this.chart?.dispose();

        const el = this.$refs.coverageChart;
        if (!el) return;

        const rendered = renderCoverageChart({
            el,
            config,
            dark: document.documentElement.classList.contains('dark'),
            animate,
        });

        this.chart = rendered.chart;
        this.resizeObserver = rendered.resizeObserver;
    },
});
