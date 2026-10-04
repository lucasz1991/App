{{-- Donut: offene Supportfaelle nach Bearbeitungsstand, dazu Faelle ohne Bearbeiter. --}}
@php
    $statusMeta = [
        'open' => ['label' => 'Offen', 'color' => 'var(--ops-warn)'],
        'in_progress' => ['label' => 'In Bearbeitung', 'color' => 'var(--ops-signal)'],
        'waiting_user' => ['label' => 'Rückfrage', 'color' => 'var(--ops-muted)'],
        'resolved' => ['label' => 'Gelöst', 'color' => 'var(--ops-ok)'],
    ];
    $segments = collect($statusMeta)->map(fn ($meta, $status) => [
        'label' => $meta['label'], 'color' => $meta['color'], 'count' => (int) ($data['byStatus'][$status] ?? 0),
    ])->values()->all();
@endphp
<div class="wv-gauge-row" style="justify-content:space-between;">
    <div style="min-width:0;">
        <span class="widget-primary-val">{{ $data['open'] }}</span>
        <span class="widget-primary-lbl">{{ $data['scope'] === 'team' ? 'offene Fälle im Team' : 'eigene offene Fälle' }}</span>
        @if($data['unassigned'])
            <p class="wv-sub"><span class="wv-pill ops-tone-warn"><i data-feather="user-x"></i>{{ $data['unassigned'] }} ohne Bearbeiter</span></p>
        @elseif($data['open'] === 0)
            <p class="wv-sub"><span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Alles erledigt</span></p>
        @endif
    </div>
    @if($data['open'] > 0)
        <x-dashboard.donut :segments="$segments" :value="$data['open']" />
    @endif
</div>
@if($rows === 2)
    @if($data['open'] > 0)
        <div class="widget-segment-legend">
            @foreach($segments as $segment)
                @continue($segment['count'] === 0)
                <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
            @endforeach
        </div>
    @endif
    <div class="widget-detail wv-list">
        @forelse($data['cases'] as $case)
            <div class="wv-item">
                <span class="wv-item-main"><span class="wv-truncate">{{ \Illuminate\Support\Str::limit($case->subject, 60) }}</span><small class="wv-truncate">{{ $case->user?->name }} · {{ $case->updated_at?->diffForHumans() }}</small></span>
                <span class="ops-badge">{{ $statusMeta[$case->status]['label'] ?? str($case->status)->replace('_', ' ')->title() }}</span>
            </div>
        @empty
            <x-dashboard.empty icon="check-circle">Keine offenen Fälle.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>IT-Support öffnen →</a>
