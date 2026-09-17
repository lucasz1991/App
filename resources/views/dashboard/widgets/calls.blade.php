<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-brand"><i data-feather="phone"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['thisWeek'] }}</span>
        <span class="ops-kpi-lbl">Anrufe diese Woche</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:14px;">
        <x-dashboard.sparkline :values="$data['sparkline']" :labels="$data['sparklineLabels']" />
        <p class="ops-muted" style="margin-top:4px;">Verlauf, letzte 7 Tage</p>
    </div>
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $room)
            <div class="ops-row"><span>{{ $room->name ?? 'Anruf' }}</span><span class="ops-muted">{{ $room->ended_at?->diffForHumans() }}</span></div>
        @empty
            <div class="ops-empty">Keine abgeschlossenen Anrufe.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Anrufverlauf öffnen →</a>
