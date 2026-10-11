{{--
    Besetzung der naechsten 7 Tage: Kennzahlen, je Tag eine Saeule
    (hell = benoetigt, rot = zugesagt, gelbe Kante = Luecke) und bei zwei
    Zeilen das Detaildiagramm plus die Luecken, die zuerst zu schliessen sind.
--}}
@php
    $coverage = $data['coverage'];
@endphp
@if($rows === 2)
<div class="wv-kpis">
    <div class="wv-kpi" data-tone="{{ $coverage === null ? '' : ($coverage >= 100 ? 'ok' : 'warn') }}"><b>{{ $coverage === null ? '—' : $coverage.' %' }}</b><span>Abdeckung</span></div>
    <div class="wv-kpi" data-tone="{{ $data['openSlots'] > 0 ? 'warn' : 'ok' }}"><b>{{ $data['openSlots'] }}</b><span>offene Plätze</span></div>
    <div class="wv-kpi" data-tone="{{ $data['criticalDays'] > 0 ? 'warn' : ($data['shiftCount'] > 0 ? 'ok' : '') }}" title="{{ $data['shiftCount'] }} Dienste in den nächsten 7 Tagen"><b>{{ $data['criticalDays'] }}</b><span>{{ $data['criticalDays'] === 1 ? 'Tag knapp' : 'Tage knapp' }}</span></div>
</div>
@else
<div class="wv-kpi-chips">
    <span data-tone="{{ $coverage === null ? '' : ($coverage >= 100 ? 'ok' : 'warn') }}"><b>{{ $coverage === null ? '—' : $coverage.' %' }}</b>Abdeckung</span>
    <span @if($data['openSlots'] > 0) data-tone="warn" @endif><b>{{ $data['openSlots'] }}</b>offen</span>
    <span @if($data['criticalDays'] > 0) data-tone="warn" @endif title="{{ $data['shiftCount'] }} Dienste in den nächsten 7 Tagen"><b>{{ $data['criticalDays'] }}</b>{{ $data['criticalDays'] === 1 ? 'Tag knapp' : 'Tage knapp' }}</span>
</div>
@endif
@if($rows === 2)
    <div class="widget-detail" style="overflow:visible;flex-shrink:0;" x-data="operationsCoverageChart(@js($data))">
        <div class="ops-chart-legend" style="margin-bottom:6px;"><span><i style="background:var(--ops-line)"></i>Benötigt</span><span><i style="background:var(--ops-signal)"></i>Zugesagt</span></div>
        <div class="ops-chart-canvas" style="height:{{ $data['gaps'] ? 112 : 190 }}px;" x-ref="coverageChart" wire:ignore role="img" aria-label="Besetzung der nächsten sieben Tage: zugesagt gegenüber benötigt"></div>
    </div>
    @if($data['gaps'])
        <div class="widget-detail">
            <p class="wv-label">Zuerst besetzen @if($data['gapCount'] > 2)<span style="text-transform:none;letter-spacing:0;font-weight:600;">· {{ $data['gapCount'] }} Lücken gesamt</span>@endif</p>
            @foreach(array_slice($data['gaps'], 0, 2) as $gap)
                <a class="wv-gap-row" href="{{ $gap['href'] }}" wire:navigate>
                    <span class="wv-gap-when">{{ $gap['when']->format('H:i') }}<small>{{ $gap['when']->isToday() ? 'Heute' : ($gap['when']->isTomorrow() ? 'Morgen' : $gap['when']->translatedFormat('D d.m.')) }}</small></span>
                    <span class="wv-truncate">{{ $gap['title'] }}</span>
                    <span class="wv-gap-missing" title="{{ $gap['reserved'] }} von {{ $gap['required'] }} zugesagt">{{ $gap['missing'] }} fehlt</span>
                </a>
            @endforeach
        </div>
    @endif
@else
    <div class="wv-gap">
        <x-dashboard.columns
            :values="$data['required']" :fill="$data['reserved']" :labels="$data['labels']" :axis="$data['weekdays']"
            value-label="benötigt" fill-label="zugesagt" :warn-short="true" :height="32"
            aria-label="Besetzung je Tag: zugesagt gegenüber benötigt"
        />
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Schichtplan öffnen →</a>
