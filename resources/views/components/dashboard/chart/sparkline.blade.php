{{--
    Reine CSS-Sparkline fuer kurze Zeitreihen (z.B. Anrufe/Nachrichten der
    letzten 7 Tage). $values: Liste von Zahlen, aeltester zuerst. $labels
    (optional): gleich lang, fuer den title-Tooltip je Balken.
    Huelle und gemeinsame Props: x-dashboard.chart.frame.
--}}
@props(['values', 'labels' => null, 'ariaLabel' => ''])
@php
    $max = max(1, max($values ?: [0]));
@endphp
<x-dashboard.chart.frame type="sparkline" :aria-label="$ariaLabel" {{ $attributes->class(['widget-sparkline']) }}>
    @foreach($values as $index => $value)
        <span
            style="height:{{ $value > 0 ? max(8, round($value / $max * 100)) : 3 }}%;"
            title="{{ ($labels[$index] ?? '').': '.$value }}"
        ></span>
    @endforeach
</x-dashboard.chart.frame>
