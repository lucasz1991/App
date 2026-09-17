<span class="widget-primary-val">{{ $data['count'] }}</span>
<span class="widget-primary-lbl">{{ $data['label'] }}</span>
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
        <div class="widget-detail widget-donut-row">
            <x-dashboard.donut :segments="$segments" :value="$data['count']" />
            <div class="widget-segment-legend">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div class="widget-detail">
        @forelse($data['recent'] as $order)
            <div class="ops-row"><span>{{ $order->title }}</span><span class="ops-muted">{{ $order->customer?->company_name }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Leistungen.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Leistungen öffnen →</a>
