{{-- Kennzahlen + Tageskacheln (1 Zeile) bzw. Saeulendiagramm (2 Zeilen): zugesagt gegenueber benoetigt. --}}
@php
    $coverage = $data['coverage'];
@endphp
<div class="wv-kpis">
    <div class="wv-kpi" data-tone="{{ $coverage === null ? '' : ($coverage >= 100 ? 'ok' : 'warn') }}"><b>{{ $coverage === null ? '—' : $coverage.' %' }}</b><span>Abdeckung</span></div>
    <div class="wv-kpi" data-tone="{{ $data['openSlots'] > 0 ? 'warn' : 'ok' }}"><b>{{ $data['openSlots'] }}</b><span>offene Plätze</span></div>
    <div class="wv-kpi" data-tone="{{ $data['criticalDays'] > 0 ? 'warn' : ($data['shiftCount'] > 0 ? 'ok' : '') }}" title="{{ $data['shiftCount'] }} Dienste in den nächsten 7 Tagen"><b>{{ $data['criticalDays'] }}</b><span>{{ $data['criticalDays'] === 1 ? 'Tag knapp' : 'Tage knapp' }}</span></div>
</div>
@if($rows === 2)
    <div class="widget-detail" style="overflow:visible;" x-data="operationsCoverageChart(@js($data))">
        <div class="ops-chart-legend" style="margin-bottom:8px;"><span><i style="background:var(--ops-line)"></i>Benötigt</span><span><i style="background:var(--ops-signal)"></i>Zugesagt</span></div>
        <div class="ops-chart-canvas" style="height:180px;" x-ref="coverageChart" wire:ignore role="img" aria-label="Besetzung der nächsten sieben Tage: zugesagt gegenüber benötigt"></div>
    </div>
@else
    <div class="wv-daycells" role="list" aria-label="Besetzung je Tag">
        @foreach($data['required'] as $index => $need)
            @php
                $have = $data['reserved'][$index];
                $state = $need === 0 ? 'empty' : ($have >= $need ? 'ok' : 'warn');
            @endphp
            <span class="wv-daycell" role="listitem" data-state="{{ $state }}" title="{{ $data['labels'][$index] }}: {{ $have }} von {{ $need }} zugesagt">{{ $data['weekdays'][$index] }}<small>{{ $need === 0 ? '–' : $have.'/'.$need }}</small></span>
        @endforeach
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Schichtplan öffnen →</a>
