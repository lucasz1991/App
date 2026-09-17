<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-brand"><i data-feather="clipboard"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['count'] }}</span>
        <span class="ops-kpi-lbl">{{ $data['label'] }}</span>
    </span>
</div>
@if($rows === 2)
    @php
        $statusMeta = [
            'requested' => ['label' => 'Angefragt', 'color' => 'var(--ops-muted)'],
            'confirmed' => ['label' => 'Bestätigt', 'color' => 'var(--ops-signal)'],
            'planned' => ['label' => 'Geplant', 'color' => 'var(--ops-ok)'],
            'in_progress' => ['label' => 'In Durchführung', 'color' => 'var(--ops-warn)'],
        ];
        $segments = collect($statusMeta)->map(fn ($meta, $status) => [
            'label' => $meta['label'], 'color' => $meta['color'], 'count' => (int) ($data['byStatus'][$status] ?? 0),
        ])->values()->all();
    @endphp
    @if($data['count'] > 0)
        <div style="display:flex;align-items:center;gap:16px;margin-top:14px;">
            <x-dashboard.donut :segments="$segments" :value="$data['count']" />
            <div class="widget-segment-legend" style="margin-top:0;">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div style="margin-top:14px;">
        @forelse($data['recent'] as $order)
            <div class="ops-row"><span>{{ $order->title }}</span><span class="ops-muted">{{ $order->customer?->company_name }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Leistungen.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Leistungen öffnen →</a>
