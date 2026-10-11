{{--
    Leistungen: Pipeline nach Phase, Prioritaeten-Balken und bei zwei
    Zeilen die Starts der naechsten 14 Tage als Saeulen plus die naechsten
    Leistungen mit Direktlink.
--}}
@php
    $stages = [
        'requested' => ['Angefragt', 'var(--ops-muted)'],
        'confirmed' => ['Bestätigt', 'var(--ops-signal)'],
        'planned' => ['Geplant', 'var(--ops-ok)'],
        'in_progress' => ['Läuft', 'var(--ops-warn)'],
    ];
    $priorities = ['urgent' => 'Dringend', 'high' => 'Hoch', 'normal' => 'Normal', 'low' => 'Niedrig'];
    $prioTotal = max(1, array_sum($data['byPriority']));
    $tz = config('operations.display_timezone');
    $axis = collect($data['startsLabels'])->map(fn ($label, $i) => $i % 2 === 1 ? '' : mb_substr($label, 0, 2))->all();
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['count'] }}</span>
    <span class="widget-primary-lbl">{{ $data['count'] === 1 ? 'offene Leistung' : 'offene Leistungen' }}</span>
    @if($data['startingSoon'] > 0)
        <span class="wv-pill ops-tone-brand" title="Beginn in den nächsten 7 Tagen"><i data-feather="play-circle"></i>{{ $data['startingSoon'] }} in 7 T</span>
    @endif
</div>
<div class="wv-pipeline" role="list" aria-label="Offene Leistungen nach Phase">
    @foreach($stages as $status => [$label, $color])
        @php
            $stageCount = (int) ($data['byStatus'][$status] ?? 0);
        @endphp
        <div class="wv-stage" role="listitem" style="--stage:{{ $color }};" @if($stageCount > 0) data-active @endif aria-label="{{ $label }}: {{ $stageCount }}">
            <b>{{ $stageCount }}</b><span>{{ $label }}</span>
        </div>
    @endforeach
</div>
@if($data['count'] > 0)
    <div class="wv-prio-bar" role="img" aria-label="Priorität: {{ collect($priorities)->map(fn ($name, $key) => $data['byPriority'][$key].' '.$name)->implode(', ') }}">
        @foreach($priorities as $key => $name)
            @if($data['byPriority'][$key] > 0)<span data-prio="{{ $key }}" style="width:{{ $data['byPriority'][$key] / $prioTotal * 100 }}%;" title="{{ $data['byPriority'][$key] }} {{ $name }}"></span>@endif
        @endforeach
    </div>
@endif
@if($rows === 2)
    <div class="wv-chart-head">
        <p class="wv-label wv-truncate">Starts · 14 Tage</p>
        @if($data['urgent'] > 0)<span class="wv-pill ops-tone-warn" title="Leistungen mit hoher oder dringender Priorität"><i data-feather="alert-circle"></i>{{ $data['urgent'] }} prioritär</span>@endif
    </div>
    <x-dashboard.columns
        :values="$data['starts']" :labels="$data['startsLabels']" :axis="$axis" :marks="$data['startsWeekend']"
        value-label="Leistungen beginnen" :height="40"
        aria-label="Beginn offener Leistungen in den nächsten 14 Tagen: {{ array_sum($data['starts']) }}"
    />
    <div class="widget-detail wv-list">
        @forelse($data['recent']->take(1) as $order)
            <a class="wv-item" href="{{ $data['orderHrefs'][$order->id] }}" wire:navigate>
                <span class="wv-prio" data-prio="{{ $order->priority?->value }}" title="Priorität: {{ $priorities[$order->priority?->value] ?? $order->priority?->value }}"></span>
                <span class="wv-item-main">
                    <span class="wv-truncate">{{ $order->title }}</span>
                    <small class="wv-truncate">{{ $order->customer?->company_name ?? 'Ohne Kunde' }} · ab {{ $order->starts_at?->copy()->setTimezone($tz)->format('d.m.') }}</small>
                </span>
                <span class="ops-badge">{{ $stages[$order->status?->value][0] ?? $order->status?->value }}</span>
            </a>
        @empty
            <x-dashboard.empty icon="check-circle">Keine offenen Leistungen.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Leistungen öffnen →</a>
