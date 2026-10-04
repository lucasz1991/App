{{--
    Waffel-Raster: 40 Kaestchen = 100 % der Flotte (je 2,5 %), eingeteilt in
    "im Einsatz" und "im Lager". Geraete mit Handlungsbedarf ueberschneiden
    sich mit beiden Gruppen (ein zugewiesenes Geraet kann zugleich auffaellig
    sein) - sie stehen deshalb als eigener Hinweis daneben statt als dritter
    Anteil, der die Summe ueber 100 % treiben wuerde.
--}}
@if($data['stats']['available'])
    @php
        $stats = $data['stats'];
        $total = $stats['total'];
        $cells = 40;
        $inUse = min($stats['assigned'], $total);
        $inStock = min($stats['inventory'], max(0, $total - $inUse));
        $shares = ['ok' => $inUse, 'inv' => $inStock];
        $counts = [];
        $remainders = [];
        foreach ($shares as $key => $value) {
            $exact = $total > 0 ? $value / $total * $cells : 0;
            $counts[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $counts[$key];
        }
        arsort($remainders);
        $missing = $total > 0 ? (int) round(($inUse + $inStock) / $total * $cells) - array_sum($counts) : 0;
        foreach (array_keys($remainders) as $key) {
            if ($missing-- <= 0) {
                break;
            }
            $counts[$key]++;
        }
        $grid = array_merge(array_fill(0, $counts['ok'], 'ok'), array_fill(0, $counts['inv'], 'inv'));
        $grid = array_pad($grid, $cells, '');
        $columns = $rows === 2 ? ($size === 'lg' ? 20 : 10) : ($size === 'lg' ? 40 : 20);
    @endphp
    <div class="wv-inline">
        <span class="widget-primary-val">{{ $total }}</span>
        <span class="widget-primary-lbl">Geräte</span>
        @if($rows === 1 && $stats['attention'] > 0)
            <span class="wv-pill ops-tone-warn"><i data-feather="alert-triangle"></i>{{ $stats['attention'] }} Handlungsbedarf</span>
        @else
            <span class="wv-pill {{ $total > 0 ? 'ops-tone-ok' : '' }}">{{ $data['utilization'] }} % im Einsatz</span>
        @endif
    </div>
    <div class="wv-waffle" style="--cols:{{ $columns }};" role="img" aria-label="{{ $inUse }} im Einsatz, {{ $inStock }} im Lager, {{ max(0, $total - $inUse - $inStock) }} sonstige von {{ $total }} Geräten">
        @foreach($grid as $state)<i @if($state) data-s="{{ $state }}" @endif></i>@endforeach
    </div>
    @if($rows === 2)
        <div class="widget-segment-legend">
            <span><i class="widget-segment-ok"></i>Im Einsatz {{ $inUse }}</span>
            <span><i class="widget-segment-neutral" style="opacity:.5;"></i>Im Lager {{ $inStock }}</span>
            <span><i style="background:var(--ops-line);"></i>Sonstige {{ max(0, $total - $inUse - $inStock) }}</span>
        </div>
        <p class="wv-sub wv-gap">
            @if($stats['attention'] > 0)
                <span class="wv-pill ops-tone-warn"><i data-feather="alert-triangle"></i>{{ $stats['attention'] }} mit Handlungsbedarf</span>
            @else
                <span class="wv-pill ops-tone-ok"><i data-feather="shield"></i>Alle Geräte konform</span>
            @endif
        </p>
    @endif
    @if($data['href'])<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Geräte & Lager öffnen →</a>@endif
@else
    <x-dashboard.empty icon="monitor">Keine Gerätedaten verfügbar.</x-dashboard.empty>
@endif
