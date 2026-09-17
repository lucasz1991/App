<span class="widget-primary-val">{{ $data['pending'] }}</span>
<span class="widget-primary-lbl">Motive warten auf Freigabe</span>
@if($rows === 2)
    @php
        $segments = [
            ['label' => 'Stellenanzeigen', 'color' => 'var(--ops-signal)', 'count' => (int) ($data['byType']['job'] ?? 0)],
            ['label' => 'Info-Motive', 'color' => 'var(--ops-muted)', 'count' => (int) ($data['byType']['info'] ?? 0)],
        ];
    @endphp
    @if($data['pending'] > 0)
        <div class="widget-detail widget-donut-row">
            <x-dashboard.donut :segments="$segments" :value="$data['pending']" />
            <div class="widget-segment-legend">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div class="widget-detail">
        @forelse($data['recent'] as $creative)
            <div class="ops-row"><span>{{ $creative['title'] }}</span><span class="ops-muted">{{ $creative['typeLabel'] }}</span></div>
        @empty
            <div class="ops-empty">Nichts wartet auf Freigabe.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Marketing-Motive öffnen →</a>
