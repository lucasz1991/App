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
    const red = '#e4002b';
    const activityPointBorder = dark ? '#111827' : '#ffffff';
    const activityAreaStart = dark ? 'rgba(228, 0, 43, 0.34)' : 'rgba(228, 0, 43, 0.18)';
    const activityAreaEnd = dark ? 'rgba(228, 0, 43, 0.02)' : 'rgba(228, 0, 43, 0.01)';
    const fontFamily = 'Plus Jakarta Sans Variable, sans-serif';
    const growth = config.userGrowth || { labels: [], totals: [], registrations: [] };
    const activity = config.activity || { labels: [], values: [] };
    const status = config.status || { labels: [], values: [] };
    const animation = reduceMotion || !animate ? {
        animation: false,
    } : {
        animation: true,
        animationDuration: 880,
        animationEasing: 'cubicOut',
        animationDelay: (index) => Math.min(index * 34, 280),
    };
    const tooltip = {
        backgroundColor: surfaceColor,
        borderColor: gridColor,
        borderWidth: 1,
        padding: [9, 11],
        textStyle: { color: strongText, fontFamily, fontSize: 12 },
        extraCssText: `border-radius:10px;box-shadow:0 12px 30px ${dark ? 'rgba(0,0,0,.34)' : 'rgba(15,23,42,.12)'};`,
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
        const registrationsMax = Math.max(1, ...(growth.registrations || []));
        const compact = refs.growthChart.clientWidth < 560;

        mount(refs.growthChart, {
            textStyle: { fontFamily },
            aria: { enabled: true },
            grid: { top: 18, right: 10, bottom: 12, left: 8, containLabel: true },
            tooltip: { ...tooltip, trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: gridColor } } },
            xAxis: {
                type: 'category',
                data: growth.labels || [],
                boundaryGap: true,
                axisLine: { lineStyle: { color: gridColor } },
                axisTick: { show: false },
                axisLabel: { color: textColor, fontSize: 10, margin: 14, interval: compact ? 3 : 1 },
            },
            yAxis: [
                {
                    type: 'value',
                    min: 0,
                    minInterval: 1,
                    axisLine: { show: false },
                    axisTick: { show: false },
                    axisLabel: { color: textColor, fontSize: 10, margin: 12 },
                    splitLine: { lineStyle: { color: gridColor, width: 1 } },
                },
                { type: 'value', min: 0, max: registrationsMax, show: false },
            ],
            series: [
                {
                    name: config.labels?.total || 'Gesamt',
                    type: 'line',
                    data: growth.totals || [],
                    smooth: 0.32,
                    showSymbol: false,
                    symbol: 'circle',
                    symbolSize: 7,
                    lineStyle: { color: strongText, width: 2.5, cap: 'round' },
                    itemStyle: { color: strongText, borderColor: surfaceColor, borderWidth: 2 },
                    emphasis: { focus: 'series', scale: 1.15 },
                    z: 3,
                },
                {
                    name: config.labels?.registrations || 'Neu',
                    type: 'bar',
                    yAxisIndex: 1,
                    data: growth.registrations || [],
                    barWidth: compact ? 4 : 6,
                    itemStyle: { color: red, borderRadius: [4, 4, 0, 0] },
                    emphasis: { itemStyle: { color: '#f51b3b' } },
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
                text: new Intl.NumberFormat(document.documentElement.lang || 'de-DE').format(totalAccounts),
                subtext: config.labels?.accounts || 'Konten',
                left: 'center',
                top: '34%',
                textStyle: { color: strongText, fontFamily, fontSize: 27, fontWeight: 650 },
                subtextStyle: { color: textColor, fontFamily, fontSize: 10, lineHeight: 18 },
            },
            tooltip: { ...tooltip, trigger: 'item', formatter: '{b}: <strong>{c}</strong> ({d}%)' },
            series: [{
                type: 'pie',
                radius: ['73%', '84%'],
                center: ['50%', '48%'],
                startAngle: 90,
                clockwise: true,
                avoidLabelOverlap: true,
                itemStyle: { borderWidth: 0 },
                label: { show: false },
                emphasis: { scale: true, scaleSize: 4 },
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
                axisPointer: { type: 'line', lineStyle: { color: gridColor } },
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
                smooth: 0.28,
                symbol: 'circle',
                symbolSize: 5,
                showSymbol: true,
                lineStyle: { color: red, width: 2 },
                itemStyle: { color: red, borderColor: activityPointBorder, borderWidth: 2 },
                areaStyle: {
                    color: {
                        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: activityAreaStart },
                            { offset: 1, color: activityAreaEnd },
                        ],
                    },
                },
                emphasis: { focus: 'series', scale: 1.25 },
            }],
        });
    }

    return { charts, resizeObserver };
}
