{{--
    Flaechen-Liniendiagramm des Kontenbestands (14 Tage) - eigenes SVG statt
    x-ui.dashboard.trend-chart: das brachte einen zweiten Titel mit und hatte
    eine feste Hoehe, hier waechst die Linie mit der Kachel (1 oder 2 Zeilen).
--}}
@php
    $values = array_values($data['values']);
    $labels = array_values($data['labels']);
    $count = count($values);
    $high = $count ? max($values) : 0;
    $low = $count ? min($values) : 0;
    $floor = $low - max(1, ($high - $low) * 0.25);
    $span = max(1, $high - $floor);
    $points = [];
    foreach ($values as $index => $value) {
        $points[] = [
            round($count > 1 ? $index / ($count - 1) * 100 : 50, 2),
            round(38 - ($value - $floor) / $span * 34, 2),
        ];
    }
    $line = implode(' ', array_map(fn ($point) => $point[0].','.$point[1], $points));
    $last = end($points) ?: [100, 38];
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['total'] }}</span>
    <span class="widget-primary-lbl">Konten gesamt</span>
    <span class="wv-pill {{ $data['delta'] > 0 ? 'ops-tone-ok' : '' }}" title="Neue Konten in den letzten 14 Tagen"><i data-feather="trending-up"></i>+{{ $data['delta'] }} in 14 T</span>
</div>
@if($count > 1)
    <div class="wv-line" role="img" aria-label="Kontenbestand der letzten 14 Tage, von {{ $values[0] }} auf {{ $data['total'] }}">
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true">
            <polygon class="wv-line-area" points="0,40 {{ $line }} 100,40"></polygon>
            <polyline class="wv-line-stroke" points="{{ $line }}" vector-effect="non-scaling-stroke"></polyline>
        </svg>
        <span class="wv-line-dot" style="top:{{ round($last[1] / 40 * 100, 1) }}%;"></span>
    </div>
    <div class="wv-line-axis" aria-hidden="true">
        <span>{{ $labels[0] ?? '' }}</span>
        @if($rows === 2)<span>{{ $labels[intdiv($count, 2)] ?? '' }}</span>@endif
        <span>{{ $labels[$count - 1] ?? '' }}</span>
    </div>
@else
    <x-dashboard.empty icon="trending-up">Noch kein Verlauf vorhanden.</x-dashboard.empty>
@endif
