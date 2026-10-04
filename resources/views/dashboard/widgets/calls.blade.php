{{-- Punkt-Achse: jeder Anruf der letzten 7 Tage als Punkt auf seinem Tag, dazu die Gespraechszeit. --}}
@php
    $talk = $data['talkSeconds'];
    $talkLabel = $talk >= 3600 ? intdiv($talk, 3600).' h '.intdiv($talk % 3600, 60).' min' : intdiv($talk, 60).' min';
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['thisWeek'] }}</span>
    <span class="widget-primary-lbl">{{ $data['thisWeek'] === 1 ? 'Anruf' : 'Anrufe' }} · 7 Tage</span>
    <span class="wv-pill" title="Gesprächszeit der letzten 7 Tage"><i data-feather="clock"></i>{{ $talkLabel }}</span>
</div>
<div class="wv-dots" role="img" aria-label="Anrufe je Tag in den letzten 7 Tagen">
    @foreach($data['dots'] as $day)
        <span class="wv-dots-col" @if($day['today']) data-today @endif title="{{ $day['label'] }}: {{ $day['count'] }} {{ $day['count'] === 1 ? 'Anruf' : 'Anrufe' }}">
            @for($i = 0; $i < ($day['count'] > 3 ? 2 : $day['count']); $i++)<i></i>@endfor
            @if($day['count'] > 3)<small>+{{ $day['count'] - 2 }}</small>@endif
        </span>
    @endforeach
</div>
<div class="wv-dots-labels" aria-hidden="true">
    @foreach($data['dots'] as $day)<span @if($day['today']) data-today @endif>{{ $day['label'] }}</span>@endforeach
</div>
@if($rows === 2)
    <div class="widget-detail wv-list">
        @forelse($data['recent'] as $room)
            @php($seconds = $room->started_at && $room->ended_at ? max(0, (int) $room->started_at->diffInSeconds($room->ended_at)) : null)
            <div class="wv-item">
                <span class="wv-item-ico ops-tone-brand"><i data-feather="video"></i></span>
                <span class="wv-item-main"><span class="wv-truncate">{{ $room->name ?? 'Anruf' }}</span><small>{{ $room->ended_at?->diffForHumans() }}</small></span>
                @if($seconds !== null)<span class="ops-muted" style="font-size:12px;white-space:nowrap;">{{ max(1, intdiv($seconds, 60)) }} min</span>@endif
            </div>
        @empty
            <x-dashboard.empty icon="phone">Keine abgeschlossenen Anrufe.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Anrufverlauf öffnen →</a>
