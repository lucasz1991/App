import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { AriaComponent, GridComponent, TitleComponent, TooltipComponent } from 'echarts/components';
import { LabelLayout } from 'echarts/features';
import { SVGRenderer } from 'echarts/renderers';

echarts.use([
    BarChart,
    LineChart,
    PieChart,
    AriaComponent,
    GridComponent,
    TitleComponent,
    TooltipComponent,
    LabelLayout,
    SVGRenderer,
]);

export function renderAdminDashboardCharts({ refs, config = {}, dark = false, animate = true }) {
    const charts = [];
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const textColor = dark ? '#a9b6c9' : '#64748b';
    const strongText = dark ? '#f8fafc' : '#172033';
    const gridColor = dark ? '#273449' : '#e2e8f0';
    const surfaceColor = dark ? '#111827' : '#ffffff';
    const mutedSurface = dark ? '#273449' : '#e8edf4';
    // Auf dunkler Flaeche traegt das hellere Markenrot besser (Kontrast >= 3:1),
    // im Hellen bleibt das Original-Markenrot.
    const red = dark ? '#fb3b57' : '#e4002b';
    const redSoft = dark ? 'rgba(251, 59, 87, 0.55)' : 'rgba(228, 0, 43, 0.55)';
    const activityPointBorder = dark ? '#111827' : '#ffffff';
    const activityAreaStart = dark ? 'rgba(251, 59, 87, 0.30)' : 'rgba(228, 0, 43, 0.16)';
    const activityAreaEnd = dark ? 'rgba(251, 59, 87, 0.02)' : 'rgba(228, 0, 43, 0.01)';
    const totalsAreaStart = dark ? 'rgba(248, 250, 252, 0.10)' : 'rgba(23, 32, 51, 0.07)';
    const totalsAreaEnd = dark ? 'rgba(248, 250, 252, 0.0)' : 'rgba(23, 32, 51, 0.0)';
    const fontFamily = 'Plus Jakarta Sans Variable, sans-serif';
    const numberFormatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
    const growth = config.userGrowth || { labels: [], totals: [], registrations: [] };
    const activity = config.activity || { labels: [], values: [] };
    const status = config.status || { labels: [], values: [] };
    const animation = reduceMotion || !animate ? {
        animation: false,
    } : {
        animation: true,
        animationDuration: 920,
        animationEasing: 'cubicOut',
        animationDelay: (index) => Math.min(index * 30, 260),
    };
    const tooltip = {
        backgroundColor: surfaceColor,
        borderColor: gridColor,
        borderWidth: 1,
        padding: [10, 12],
        textStyle: { color: strongText, fontFamily, fontSize: 12 },
        extraCssText: `border-radius:12px;box-shadow:0 16px 38px ${dark ? 'rgba(0,0,0,.44)' : 'rgba(15,23,42,.14)'};`,
    };
    const crosshair = {
        type: 'line',
        lineStyle: { color: dark ? '#3b4d68' : '#c4d0de', type: [4, 4], width: 1 },
    };
    const resizeObserver = typeof ResizeObserver === 'undefined'
        ? null
        : new ResizeObserver((entries) => {
            entries.forEach(({ target }) => echarts.getInstanceByDom(target)?.resize());
        });

    const mount = (element, option) => {
        if (!element) return;

        echarts.getInstanceByDom(element)?.dispose();

        const chart = echarts.init(element, null, { renderer: 'svg' });
        chart.setOption({ ...animation, ...option }, { notMerge: true, lazyUpdate: false });
        charts.push(chart);
        resizeObserver?.observe(element);

        window.requestAnimationFrame(() => {
            if (!chart.isDisposed()) chart.resize();
        });
    };

    if (refs.growthChart) {
        const totals = growth.totals || [];
        const registrations = growth.registrations || [];
        const labels = growth.labels || [];
        const compact = refs.growthChart.clientWidth < 560;
        // Eine Achse pro Skala: Gesamtverlauf und Neuregistrierungen teilen
        // sich die X-Achse, leben aber in zwei eigenen, klar getrennten
        // Panels (Kurs+Volumen-Muster) statt in einer Doppelachse.
        const yLabelWidth = Math.max(34, 14 + 8 * String(Math.max(0, ...totals)).length);

        mount(refs.growthChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            axisPointer: { link: [{ xAxisIndex: 'all' }] },
            grid: [
                { top: 14, right: 10, left: yLabelWidth, height: '56%' },
                { top: '74%', right: 10, left: yLabelWidth, bottom: 24 },
            ],
            tooltip: {
                ...tooltip,
                trigger: 'axis',
                axisPointer: crosshair,
                formatter: (items) => {
                    const index = items?.[0]?.dataIndex;
                    if (index === undefined) return '';

                    const totalLabel = config.labels?.total || 'Gesamt';
                    const regLabel = config.labels?.registrations || 'Neu';

                    return [
                        `<span style="color:${textColor};font-size:11px;">${labels[index] ?? ''}</span>`,
                        `${totalLabel}: <strong>${numberFormatter.format(totals[index] ?? 0)}</strong>`,
                        `${regLabel}: <strong>+${numberFormatter.format(registrations[index] ?? 0)}</strong>`,
                    ].join('<br>');
                },
            },
            xAxis: [
                {
                    type: 'category',
                    gridIndex: 0,
                    data: labels,
                    boundaryGap: true,
                    show: false,
                },
                {
                    type: 'category',
                    gridIndex: 1,
                    data: labels,
                    boundaryGap: true,
                    axisLine: { lineStyle: { color: gridColor } },
                    axisTick: { show: false },
                    axisLabel: { color: textColor, fontSize: 10, margin: 10, interval: compact ? 3 : 1 },
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
                    axisLabel: { color: textColor, fontSize: 10, margin: 10 },
                    splitLine: { lineStyle: { color: gridColor, width: 1, type: [3, 5] } },
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
                    name: config.labels?.total || 'Gesamt',
                    type: 'line',
                    xAxisIndex: 0,
                    yAxisIndex: 0,
                    data: totals,
                    smooth: 0.34,
                    showSymbol: false,
                    symbol: 'circle',
                    symbolSize: 8,
                    lineStyle: { color: strongText, width: 2, cap: 'round' },
                    itemStyle: { color: strongText, borderColor: surfaceColor, borderWidth: 2 },
                    areaStyle: {
                        color: {
                            type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: totalsAreaStart },
                                { offset: 1, color: totalsAreaEnd },
                            ],
                        },
                    },
                    emphasis: { focus: 'series', scale: 1.2 },
                    z: 3,
                },
                {
                    name: config.labels?.registrations || 'Neu',
                    type: 'bar',
                    xAxisIndex: 1,
                    yAxisIndex: 1,
                    data: registrations,
                    barWidth: compact ? 5 : 7,
                    itemStyle: {
                        borderRadius: [3, 3, 0, 0],
                        color: {
                            type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: red },
                                { offset: 1, color: redSoft },
                            ],
                        },
                    },
                    emphasis: { itemStyle: { color: dark ? '#ff5c73' : '#f51b3b' } },
                    z: 2,
                },
            ],
        });
    }

    if (refs.statusChart) {
        const totalAccounts = (status.values || []).reduce((sum, value) => sum + Number(value || 0), 0);

        mount(refs.statusChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            title: {
                text: numberFormatter.format(totalAccounts),
                subtext: config.labels?.accounts || 'Konten',
                left: 'center',
                top: '34%',
                textStyle: { color: strongText, fontFamily, fontSize: 27, fontWeight: 650 },
                subtextStyle: { color: textColor, fontFamily, fontSize: 10, lineHeight: 18 },
            },
            tooltip: { ...tooltip, trigger: 'item', formatter: '{b}: <strong>{c}</strong> ({d}%)' },
            series: [{
                type: 'pie',
                radius: ['72%', '86%'],
                center: ['50%', '48%'],
                startAngle: 90,
                clockwise: true,
                avoidLabelOverlap: true,
                // 2px Flaechenluecke zwischen den Segmenten + weiche Kappen.
                itemStyle: { borderRadius: 7, borderColor: surfaceColor, borderWidth: 2 },
                label: { show: false },
                emphasis: {
                    scale: true,
                    scaleSize: 5,
                    itemStyle: { shadowBlur: 18, shadowColor: dark ? 'rgba(0,0,0,.5)' : 'rgba(15,23,42,.22)' },
                },
                data: (status.values || []).map((value, index) => ({
                    value,
                    name: status.labels?.[index] || '',
                    itemStyle: { color: index === 0 ? red : mutedSurface },
                })),
            }],
        });
    }

    if (refs.activityChart) {
        mount(refs.activityChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            grid: { top: 12, right: 8, bottom: 8, left: 8 },
            tooltip: {
                ...tooltip,
                trigger: 'axis',
                axisPointer: crosshair,
                formatter: (items) => {
                    const point = items?.[0];
                    return point ? `${activity.labels?.[point.dataIndex] || ''}<br><strong>${point.value}</strong> ${config.labels?.activity || ''}` : '';
                },
            },
            xAxis: { type: 'category', data: activity.labels || [], show: false, boundaryGap: false },
            yAxis: { type: 'value', show: false, min: 0, minInterval: 1 },
            series: [{
                name: config.labels?.activity || 'Aktive Nutzer',
                type: 'line',
                data: activity.values || [],
                smooth: 0.3,
                symbol: 'circle',
                symbolSize: 9,
                showSymbol: false,
                lineStyle: { color: red, width: 2, cap: 'round' },
                itemStyle: { color: red, borderColor: activityPointBorder, borderWidth: 2 },
                areaStyle: {
                    color: {
                        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: activityAreaStart },
                            { offset: 0.65, color: dark ? 'rgba(251, 59, 87, 0.10)' : 'rgba(228, 0, 43, 0.06)' },
                            { offset: 1, color: activityAreaEnd },
                        ],
                    },
                },
                emphasis: { focus: 'series', scale: 1.2 },
            }],
        });
    }

    return { charts, resizeObserver };
}
