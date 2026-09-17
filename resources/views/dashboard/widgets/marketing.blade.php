<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['pending'] === 0 ? 'ok' : 'warn' }}"><i data-feather="image"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['pending'] }}</span>
        <span class="ops-kpi-lbl">Motive warten auf Freigabe</span>
    </span>
</div>
@if($rows === 2)
    @php
        $segments = [
            ['label' => 'Stellenanzeigen', 'color' => 'var(--ops-signal)', 'count' => (int) ($data['byType']['job'] ?? 0)],
            ['label' => 'Info-Motive', 'color' => 'var(--ops-muted)', 'count' => (int) ($data['byType']['info'] ?? 0)],
        ];
    @endphp
    @if($data['pending'] > 0)
        <div style="display:flex;align-items:center;gap:16px;margin-top:14px;">
            <x-dashboard.donut :segments="$segments" :value="$data['pending']" />
            <div class="widget-segment-legend" style="margin-top:0;">
                @foreach($segments as $segment)
                    @continue($segment['count'] === 0)
                    <span><i style="background:{{ $segment['color'] }};"></i>{{ $segment['label'] }} {{ $segment['count'] }}</span>
                @endforeach
            </div>
        </div>
    @endif
    <div style="margin-top:14px;">
        @forelse($data['recent'] as $creative)
            <div class="ops-row"><span>{{ $creative['title'] }}</span><span class="ops-muted">{{ $creative['typeLabel'] }}</span></div>
        @empty
            <div class="ops-empty">Nichts wartet auf Freigabe.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Marketing-Motive öffnen →</a>
