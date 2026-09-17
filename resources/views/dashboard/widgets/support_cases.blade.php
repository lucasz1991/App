<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['open'] === 0 ? 'ok' : 'warn' }}"><i data-feather="life-buoy"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['open'] }}</span>
        <span class="ops-kpi-lbl">{{ $data['scope'] === 'team' ? 'offene Fälle im Team' : 'eigene offene Fälle' }}</span>
    </span>
</div>
@if($rows === 2)
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
    @if($data['open'] > 0)
        <div style="display:flex;align-items:center;gap:16px;margin-top:14px;">
            <x-dashboard.donut :segments="$segments" :value="$data['open']" />
            <div class="widget-segment-legend" style="margin-top:0;">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div style="margin-top:14px;">
        @forelse($data['cases'] as $case)
            <div class="ops-row"><span>{{ \Illuminate\Support\Str::limit($case->subject, 40) }}</span><span class="ops-badge">{{ str($case->status)->replace('_', ' ')->title() }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Fälle.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">IT-Support öffnen →</a>
