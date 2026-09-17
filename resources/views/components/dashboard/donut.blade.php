{{--
    Mehrsegment-Donut fuer Anteils-Widgets (z.B. Auftraege nach Status).
    $segments: Liste aus ['label' => string, 'count' => int, 'color' => CSS-Farbe].
    Segmente mit count <= 0 werden uebersprungen, damit der Gradient nicht
    mit leeren Nullbreite-Stopps kollidiert. $value ueberschreibt die Zahl
    in der Mitte (Default: Summe aller Segmente).
--}}
@props(['segments', 'value' => null])
@php
    $total = max(1, collect($segments)->sum('count'));
    $offset = 0;
    $stops = [];
    foreach ($segments as $segment) {
        if ($segment['count'] <= 0) {
            continue;
        }
        $start = round($offset / $total * 100, 2);
        $offset += $segment['count'];
        $end = round($offset / $total * 100, 2);
        $stops[] = "{$segment['color']} {$start}% {$end}%";
    }
    $background = $stops ? 'conic-gradient('.implode(', ', $stops).')' : 'var(--ops-line)';
@endphp
<div class="widget-donut" style="background:{{ $background }};">
    <span class="widget-donut-val">{{ $value ?? $total }}</span>
</div>
