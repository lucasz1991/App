<p class="ops-kpi-val" style="font-size:28px;">{{ $data['thisWeek'] }}</p>
<p class="ops-muted">Anrufe diese Woche</p>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $room)
            <div class="ops-row"><span>{{ $room->name ?? 'Anruf' }}</span><span class="ops-muted">{{ $room->ended_at?->diffForHumans() }}</span></div>
        @empty
            <div class="ops-empty">Keine abgeschlossenen Anrufe.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Anrufverlauf öffnen →</a>
