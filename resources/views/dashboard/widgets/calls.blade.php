<span class="widget-primary-val">{{ $data['thisWeek'] }}</span>
<span class="widget-primary-lbl">Anrufe diese Woche</span>
@if($rows === 2)
    <div class="widget-detail">
        <x-dashboard.sparkline :values="$data['sparkline']" :labels="$data['sparklineLabels']" />
        <p class="ops-muted" style="margin-top:4px;">Verlauf, letzte 7 Tage</p>
    </div>
    <div class="widget-detail">
        @forelse($data['recent'] as $room)
            <div class="ops-row"><span>{{ $room->name ?? 'Anruf' }}</span><span class="ops-muted">{{ $room->ended_at?->diffForHumans() }}</span></div>
        @empty
            <div class="ops-empty">Keine abgeschlossenen Anrufe.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Anrufverlauf öffnen →</a>
