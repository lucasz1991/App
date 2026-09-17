<div x-data="operationsCoverageChart(@js($data))">
    <div class="ops-chart-legend" style="margin-bottom:8px;"><span><i style="background:var(--ops-line)"></i>Benötigt</span><span><i style="background:var(--ops-signal)"></i>Zugesagt</span></div>
    <div class="ops-chart-canvas" style="height:{{ $rows === 2 ? '196px' : '140px' }};" x-ref="coverageChart" wire:ignore role="img" aria-label="Besetzung der naechsten sieben Tage: zugesagt gegenueber benoetigt"></div>
</div>
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Schichtplan öffnen →</a>
