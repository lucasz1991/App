import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import {
    AriaComponent,
    GridComponent,
    MarkLineComponent,
    MarkPointComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import { LabelLayout } from 'echarts/features';
import { SVGRenderer } from 'echarts/renderers';

echarts.use([
    BarChart,
    LineChart,
    PieChart,
    AriaComponent,
    GridComponent,
    MarkLineComponent,
    MarkPointComponent,
    TitleComponent,
    TooltipComponent,
    LabelLayout,
    SVGRenderer,
]);

/**
 * Farb- und Flaechenwerte fuer beide Themes an einer Stelle.
 *
 * Auf dunkler Flaeche traegt das hellere Markenrot besser (Kontrast >= 3:1),
 * im Hellen bleibt das Original-Markenrot.
 */
function palette(dark) {
    return {
        text: dark ? '#aeb9c9' : '#637188',
        strongText: dark ? '#f8fafc' : '#182435',
        soft: dark ? '#7c8ba1' : '#8a97a9',
        grid: dark ? '#2a394d' : '#e2e8f0',
        surface: dark ? '#111a27' : '#ffffff',
        track: dark ? '#22303f' : '#e8edf4',
        red: dark ? '#fb3b57' : '#e4002b',
        redSoft: dark ? 'rgba(251, 59, 87, 0.55)' : 'rgba(228, 0, 43, 0.55)',
        redFaint: dark ? 'rgba(251, 59, 87, 0.16)' : 'rgba(228, 0, 43, 0.12)',
        glow: dark ? 'rgba(251, 59, 87, 0.42)' : 'rgba(228, 0, 43, 0.26)',
        areaStart: dark ? 'rgba(251, 59, 87, 0.34)' : 'rgba(228, 0, 43, 0.18)',
        areaMid: dark ? 'rgba(251, 59, 87, 0.10)' : 'rgba(228, 0, 43, 0.06)',
        areaEnd: dark ? 'rgba(251, 59, 87, 0.0)' : 'rgba(228, 0, 43, 0.0)',
        totalsAreaStart: dark ? 'rgba(248, 250, 252, 0.12)' : 'rgba(23, 32, 51, 0.08)',
        totalsAreaEnd: dark ? 'rgba(248, 250, 252, 0.0)' : 'rgba(23, 32, 51, 0.0)',
        shadow: dark ? 'rgba(0,0,0,.44)' : 'rgba(15,23,42,.14)',
    };
}

function verticalGradient(stops) {
    return { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: stops };
}

function horizontalGradient(stops) {
    return { type: 'linear', x: 0, y: 0, x2: 1, y2: 0, colorStops: stops };
}

export function renderAdminDashboardCharts({ refs, config = {}, dark = false, animate = true }) {
    const charts = [];
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const c = palette(dark);
    const fontFamily = 'Plus Jakarta Sans Variable, sans-serif';
    const numberFormatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
    const growth = config.userGrowth || { labels: [], totals: [], registrations: [] };
    const activity = config.activity || { labels: [], values: [] };
    const status = config.status || { labels: [], values: [] };
    const labels = config.labels || {};
    const animation = reduceMotion || !animate ? {
        animation: false,
    } : {
        animation: true,
        animationDuration: 920,
        animationEasing: 'cubicOut',
        animationDelay: (index) => Math.min(index * 30, 260),
    };
    const tooltip = {
        backgroundColor: c.surface,
        borderColor: c.grid,
        borderWidth: 1,
        confine: true,
        padding: [10, 12],
        textStyle: { color: c.strongText, fontFamily, fontSize: 12 },
        extraCssText: `border-radius:12px;box-shadow:0 16px 38px ${c.shadow};`,
    };
    const crosshair = {
        type: 'line',
        lineStyle: { color: dark ? '#3b4d68' : '#c4d0de', type: [4, 4], width: 1 },
    };
    // Ø-Linie und Spitzenwert-Marker sind reine Lesehilfen: sie liegen hinter
    // den Datenreihen, reagieren nicht auf die Maus und bleiben tonal ruhig.
    const averageMarkLine = (name) => ({
        silent: true,
        symbol: 'none',
        animation: false,
        label: {
            position: 'insideEndTop',
            formatter: () => `Ø ${name}`,
            color: c.soft,
            fontFamily,
            fontSize: 10,
            padding: [0, 0, 2, 0],
        },
        lineStyle: { color: c.soft, type: [2, 4], width: 1, opacity: 0.75 },
        data: [{ type: 'average' }],
    });
    const peakMarkPoint = () => ({
        silent: true,
        symbol: 'circle',
        symbolSize: 9,
        animation: false,
        itemStyle: { color: c.red, borderColor: c.surface, borderWidth: 2, shadowBlur: 10, shadowColor: c.glow },
        label: { show: false },
        data: [{ type: 'max' }],
    });
    const resizeHandlers = new WeakMap();
    const resizeObserver = typeof ResizeObserver === 'undefined'
        ? null
        : new ResizeObserver((entries) => {
            entries.forEach(({ target }) => {
                const chart = echarts.getInstanceByDom(target);

                if (!chart) return;

                chart.resize();
                resizeHandlers.get(target)?.(chart, target);
            });
        });

    const mount = (element, option, onResize = null) => {
        if (!element) return;

        echarts.getInstanceByDom(element)?.dispose();

        const chart = echarts.init(element, null, { renderer: 'svg' });
        chart.setOption({ ...animation, ...option }, { notMerge: true, lazyUpdate: false });
        charts.push(chart);
        if (onResize) resizeHandlers.set(element, onResize);
        resizeObserver?.observe(element);

        window.requestAnimationFrame(() => {
            if (!chart.isDisposed()) chart.resize();
        });

        return chart;
    };

    if (refs.growthChart) {
        const totals = growth.totals || [];
        const registrations = growth.registrations || [];
        const growthLabels = growth.labels || [];
        let compact = refs.growthChart.clientWidth < 560;
        // Eine Achse pro Skala: Gesamtverlauf und Neuregistrierungen teilen
        // sich die X-Achse, leben aber in zwei eigenen, klar getrennten
        // Panels (Kurs+Volumen-Muster) statt in einer Doppelachse.
        const yLabelWidth = Math.max(34, 14 + 8 * String(Math.max(0, ...totals)).length);

        mount(refs.growthChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            axisPointer: { link: [{ xAxisIndex: 'all' }] },
            grid: [
                { top: 16, right: 12, left: yLabelWidth, height: '54%' },
                { top: '73%', right: 12, left: yLabelWidth, bottom: 26 },
            ],
            tooltip: {
                ...tooltip,
                trigger: 'axis',
                axisPointer: crosshair,
                formatter: (items) => {
                    const index = items?.[0]?.dataIndex;
                    if (index === undefined) return '';

                    const totalLabel = labels.total || 'Gesamt';
                    const regLabel = labels.registrations || 'Neu';
                    const delta = (totals[index] ?? 0) - (totals[index - 1] ?? totals[index] ?? 0);
                    const deltaMarkup = index > 0 && delta !== 0
                        ? ` <span style="color:${c.soft};font-size:11px;">(${delta > 0 ? '+' : ''}${numberFormatter.format(delta)})</span>`
                        : '';

                    return [
                        `<span style="color:${c.text};font-size:11px;">${growthLabels[index] ?? ''}</span>`,
                        `<span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:${c.strongText};margin-right:6px;"></span>${totalLabel}: <strong>${numberFormatter.format(totals[index] ?? 0)}</strong>${deltaMarkup}`,
                        `<span style="display:inline-block;width:7px;height:7px;border-radius:2px;background:${c.red};margin-right:6px;"></span>${regLabel}: <strong>+${numberFormatter.format(registrations[index] ?? 0)}</strong>`,
                    ].join('<br>');
                },
            },
            xAxis: [
                {
                    type: 'category',
                    gridIndex: 0,
                    data: growthLabels,
                    boundaryGap: true,
                    show: false,
                },
                {
                    type: 'category',
                    gridIndex: 1,
                    data: growthLabels,
                    boundaryGap: true,
                    axisLine: { lineStyle: { color: c.grid } },
                    axisTick: { show: false },
                    axisLabel: { color: c.text, fontSize: 10, margin: 10, interval: compact ? 3 : 1 },
                },
            ],
            yAxis: [
                {
                    type: 'value',
                    gridIndex: 0,
                    min: 0,
                    minInterval: 1,
                    axisLine: { show: false },
                    axisTick: { show: false },
                    axisLabel: { color: c.text, fontSize: 10, margin: 10 },
                    splitLine: { lineStyle: { color: c.grid, width: 1, type: [3, 5] } },
                },
                {
                    type: 'value',
                    gridIndex: 1,
                    min: 0,
                    max: Math.max(1, ...registrations),
                    show: false,
                },
            ],
            series: [
                {
                    name: labels.total || 'Gesamt',
                    type: 'line',
                    xAxisIndex: 0,
                    yAxisIndex: 0,
                    data: totals,
                    smooth: 0.34,
                    showSymbol: false,
                    symbol: 'circle',
                    symbolSize: 8,
                    lineStyle: {
                        width: 2.4,
                        cap: 'round',
                        color: horizontalGradient([
                            { offset: 0, color: dark ? 'rgba(248,250,252,.42)' : 'rgba(23,32,51,.34)' },
                            { offset: 1, color: c.strongText },
                        ]),
                        shadowBlur: 12,
                        shadowColor: dark ? 'rgba(0,0,0,.55)' : 'rgba(23,32,51,.18)',
                        shadowOffsetY: 4,
                    },
                    itemStyle: { color: c.strongText, borderColor: c.surface, borderWidth: 2 },
                    areaStyle: {
                        color: verticalGradient([
                            { offset: 0, color: c.totalsAreaStart },
                            { offset: 1, color: c.totalsAreaEnd },
                        ]),
                    },
                    emphasis: { focus: 'series', scale: 1.2 },
                    z: 3,
                },
                {
                    name: labels.registrations || 'Neu',
                    type: 'bar',
                    xAxisIndex: 1,
                    yAxisIndex: 1,
                    data: registrations,
                    barWidth: compact ? 5 : 8,
                    itemStyle: {
                        borderRadius: [4, 4, 1, 1],
                        color: verticalGradient([
                            { offset: 0, color: c.red },
                            { offset: 1, color: c.redSoft },
                        ]),
                    },
                    markLine: averageMarkLine(labels.average || 'Ø'),
                    emphasis: { itemStyle: { color: dark ? '#ff5c73' : '#f51b3b' } },
                    z: 2,
                },
            ],
        }, (chart, element) => {
            const nextCompact = element.clientWidth < 560;

            if (nextCompact === compact) return;

            compact = nextCompact;
            chart.setOption({
                xAxis: [
                    {},
                    { axisLabel: { interval: compact ? 3 : 1 } },
                ],
                series: [
                    {},
                    { barWidth: compact ? 5 : 8 },
                ],
            }, { lazyUpdate: true });
        });
    }

    if (refs.statusChart) {
        const values = status.values || [];
        const totalWorkforce = values.reduce((sum, value) => sum + Number(value || 0), 0);

        mount(refs.statusChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            title: {
                text: numberFormatter.format(totalWorkforce),
                subtext: labels.accounts || 'Mitarbeiter',
                left: 'center',
                top: '33%',
                textStyle: { color: c.strongText, fontFamily, fontSize: 30, fontWeight: 650 },
                subtextStyle: { color: c.text, fontFamily, fontSize: 10, lineHeight: 18 },
            },
            tooltip: { ...tooltip, trigger: 'item', formatter: '{b}: <strong>{c}</strong> ({d}%)' },
            series: [
                {
                    // Ruhige Grundspur: bleibt auch dann sichtbar, wenn noch
                    // keine Mitarbeiterkonten existieren, und traegt die
                    // Segmentluecken des Datenrings optisch mit.
                    type: 'pie',
                    radius: ['73%', '87%'],
                    center: ['50%', '48%'],
                    silent: true,
                    animation: false,
                    label: { show: false },
                    labelLine: { show: false },
                    emphasis: { disabled: true },
                    data: [{ value: 1, itemStyle: { color: c.track } }],
                    z: 1,
                },
                {
                    type: 'pie',
                    radius: ['73%', '87%'],
                    center: ['50%', '48%'],
                    startAngle: 90,
                    clockwise: true,
                    avoidLabelOverlap: true,
                    // 2px Flaechenluecke zwischen den Segmenten + weiche Kappen.
                    itemStyle: { borderRadius: 8, borderColor: c.surface, borderWidth: 2 },
                    label: { show: false },
                    labelLine: { show: false },
                    emphasis: {
                        scale: true,
                        scaleSize: 5,
                        itemStyle: { shadowBlur: 18, shadowColor: dark ? 'rgba(0,0,0,.5)' : 'rgba(15,23,42,.22)' },
                    },
                    data: values.map((value, index) => ({
                        value,
                        name: status.labels?.[index] || '',
                        itemStyle: {
                            color: index === 0
                                ? horizontalGradient([
                                    { offset: 0, color: c.redSoft },
                                    { offset: 1, color: c.red },
                                ])
                                : c.track,
                            shadowBlur: index === 0 ? 16 : 0,
                            shadowColor: index === 0 ? c.glow : 'transparent',
                        },
                    })),
                    z: 2,
                },
            ],
        });
    }

    if (refs.activityChart) {
        const values = activity.values || [];
        const activityLabels = activity.labels || [];
        let compact = refs.activityChart.clientWidth < 560;

        mount(refs.activityChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            grid: { top: 18, right: 14, bottom: 26, left: 34 },
            tooltip: {
                ...tooltip,
                trigger: 'axis',
                axisPointer: crosshair,
                formatter: (items) => {
                    const point = items?.[0];

                    if (!point) return '';

                    return [
                        `<span style="color:${c.text};font-size:11px;">${activityLabels[point.dataIndex] || ''}</span>`,
                        `<strong>${numberFormatter.format(point.value ?? 0)}</strong> ${labels.activity || ''}`,
                    ].join('<br>');
                },
            },
            xAxis: {
                type: 'category',
                data: activityLabels,
                boundaryGap: false,
                axisLine: { lineStyle: { color: c.grid } },
                axisTick: { show: false },
                axisLabel: { color: c.text, fontSize: 10, margin: 10, interval: compact ? 3 : 1 },
            },
            yAxis: {
                type: 'value',
                min: 0,
                minInterval: 1,
                splitNumber: 3,
                axisLine: { show: false },
                axisTick: { show: false },
                axisLabel: { color: c.text, fontSize: 10, margin: 8 },
                splitLine: { lineStyle: { color: c.grid, width: 1, type: [3, 5] } },
            },
            series: [{
                name: labels.activity || 'Aktive Nutzer',
                type: 'line',
                data: values,
                smooth: 0.3,
                symbol: 'circle',
                symbolSize: 9,
                showSymbol: false,
                lineStyle: {
                    color: c.red,
                    width: 2.4,
                    cap: 'round',
                    shadowBlur: 14,
                    shadowColor: c.glow,
                    shadowOffsetY: 5,
                },
                itemStyle: { color: c.red, borderColor: c.surface, borderWidth: 2 },
                areaStyle: {
                    color: verticalGradient([
                        { offset: 0, color: c.areaStart },
                        { offset: 0.65, color: c.areaMid },
                        { offset: 1, color: c.areaEnd },
                    ]),
                },
                markLine: averageMarkLine(labels.average || 'Ø'),
                markPoint: peakMarkPoint(),
                emphasis: { focus: 'series', scale: 1.2 },
            }],
        }, (chart, element) => {
            const nextCompact = element.clientWidth < 560;

            if (nextCompact === compact) return;

            compact = nextCompact;
            chart.setOption({
                xAxis: { axisLabel: { interval: compact ? 3 : 1 } },
            }, { lazyUpdate: true });
        });
    }

    return { charts, resizeObserver };
}
