import * as echarts from 'echarts/core';
import { BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';

echarts.use([BarChart, GridComponent, TooltipComponent, SVGRenderer]);

/**
 * Eigenstaendige, schlanke Palette fuer den Betriebsbereich (rt-ops).
 * Bewusst nicht aus admin-dashboard-echarts.js importiert, damit dieses
 * Modul unabhaengig lazy-geladen bleibt und keine fremde Chunk-Grenze zieht.
 * Werte spiegeln resources/css/operations-workspace.css (--ops-*).
 */
function palette(dark) {
    return {
        text: dark ? '#a6b1c1' : '#637083',
        strongText: dark ? '#edf0f5' : '#182230',
        grid: dark ? 'rgba(192,204,220,.16)' : 'rgba(83,101,122,.16)',
        surface: dark ? '#1c2736' : '#ffffff',
        track: dark ? '#28374a' : '#eef1f4',
        signal: dark ? '#ff7189' : '#e4002b',
        ok: dark ? '#8fd6ab' : '#25603b',
        shadow: dark ? 'rgba(0,0,0,.45)' : 'rgba(15,23,42,.14)',
    };
}

/**
 * Wochenbesetzung als ueberlagerter Balken: "Benoetigt" liegt als breiter,
 * stiller Massstab hinten, "Zugesagt" liegt schmaler davor und faerbt sich
 * je Tag danach, ob das Soll erreicht ist. Eine gemeinsame Werteachse -
 * kein Dual-Axis-Diagramm.
 *
 * @param {{el: HTMLElement, config?: {labels?: string[], required?: number[], reserved?: number[]}, dark?: boolean, animate?: boolean}} args
 * @returns {{chart: import('echarts/core').ECharts, resizeObserver: ResizeObserver|null}}
 */
export function renderCoverageChart({ el, config = {}, dark = false, animate = true }) {
    const c = palette(dark);
    const fontFamily = 'Plus Jakarta Sans Variable, sans-serif';
    const labels = config.labels || [];
    const required = config.required || [];
    const reserved = config.reserved || [];
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    echarts.getInstanceByDom(el)?.dispose();
    const chart = echarts.init(el, null, { renderer: 'svg' });

    chart.setOption({
        animation: !reduceMotion && animate,
        animationDuration: 640,
        animationEasing: 'cubicOut',
        textStyle: { fontFamily },
        grid: { left: 8, right: 8, top: 12, bottom: 24, containLabel: true },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            confine: true,
            backgroundColor: c.surface,
            borderColor: c.grid,
            borderWidth: 1,
            padding: [10, 12],
            textStyle: { color: c.strongText, fontFamily, fontSize: 12 },
            extraCssText: `border-radius:10px;box-shadow:0 12px 28px ${c.shadow};`,
            formatter: (params) => {
                const index = params[0]?.dataIndex ?? 0;
                const need = required[index] ?? 0;
                const have = reserved[index] ?? 0;

                return `<strong>${params[0]?.axisValueLabel ?? ''}</strong><br/>${have} zugesagt / ${need} benötigt`;
            },
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLine: { lineStyle: { color: c.grid } },
            axisTick: { show: false },
            axisLabel: { color: c.text, fontSize: 11 },
        },
        yAxis: {
            type: 'value',
            minInterval: 1,
            splitLine: { lineStyle: { color: c.grid, type: [3, 4] } },
            axisLabel: { color: c.text, fontSize: 11 },
        },
        series: [
            {
                name: 'Benötigt',
                type: 'bar',
                data: required,
                barWidth: '62%',
                z: 1,
                silent: true,
                itemStyle: { color: c.track, borderRadius: [4, 4, 0, 0] },
            },
            {
                name: 'Zugesagt',
                type: 'bar',
                data: reserved,
                barWidth: '30%',
                barGap: '-100%',
                z: 2,
                itemStyle: {
                    borderRadius: [3, 3, 0, 0],
                    color: (item) => ((reserved[item.dataIndex] ?? 0) >= (required[item.dataIndex] ?? 0) ? c.ok : c.signal),
                },
            },
        ],
    }, { notMerge: true });

    const resizeObserver = typeof ResizeObserver === 'undefined'
        ? null
        : new ResizeObserver(() => {
            if (!chart.isDisposed()) chart.resize();
        });
    resizeObserver?.observe(el);

    window.requestAnimationFrame(() => {
        if (!chart.isDisposed()) chart.resize();
    });

    return { chart, resizeObserver };
}
