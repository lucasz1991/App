{{-- Pipeline: offene Leistungen nach Phase, vom Eingang bis zur Durchfuehrung. --}}
@php
    $stages = [
        'requested' => ['Angefragt', 'var(--ops-muted)'],
        'confirmed' => ['Bestätigt', 'var(--ops-signal)'],
        'planned' => ['Geplant', 'var(--ops-ok)'],
        'in_progress' => ['Läuft', 'var(--ops-warn)'],
    ];
    $tz = config('operations.display_timezone');
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
        @php($stageCount = (int) ($data['byStatus'][$status] ?? 0))
        <div class="wv-stage" role="listitem" style="--stage:{{ $color }};" @if($stageCount > 0) data-active @endif aria-label="{{ $label }}: {{ $stageCount }}">
            <b>{{ $stageCount }}</b><span>{{ $label }}</span>
        </div>
    @endforeach
</div>
@if($rows === 2)
    <div class="widget-detail">
        @if($data['urgent'] > 0)
            <p class="wv-sub" style="margin:0 0 4px;"><span class="wv-pill ops-tone-warn"><i data-feather="alert-circle"></i>{{ $data['urgent'] }} mit hoher Priorität</span></p>
        @endif
        <div class="wv-list">
            @forelse($data['recent'] as $order)
                <a class="wv-item" href="{{ $data['orderHrefs'][$order->id] }}" wire:navigate>
                    <span class="wv-prio" data-prio="{{ $order->priority?->value }}" title="Priorität: {{ $order->priority?->value }}"></span>
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
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Leistungen öffnen →</a>
