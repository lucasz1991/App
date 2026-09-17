<span class="widget-primary-val">{{ $data['open'] }}</span>
<span class="widget-primary-lbl">{{ $data['scope'] === 'team' ? 'offene Fälle im Team' : 'eigene offene Fälle' }}</span>
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
        <div class="widget-detail widget-donut-row">
            <x-dashboard.donut :segments="$segments" :value="$data['open']" />
            <div class="widget-segment-legend">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div class="widget-detail">
        @forelse($data['cases'] as $case)
            <div class="ops-row"><span>{{ \Illuminate\Support\Str::limit($case->subject, 40) }}</span><span class="ops-badge">{{ str($case->status)->replace('_', ' ')->title() }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Fälle.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>IT-Support öffnen →</a>
