{{-- Stunden-Bilanz: wie viel Arbeitszeit auf Freigabe wartet und bei wem. --}}
@php
    $hours = fn (int $seconds) => number_format($seconds / 3600, 1, ',', '.').' h';
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $hours($data['totalSeconds']) }}</span>
    <span class="widget-primary-lbl">in {{ $data['count'] }} {{ $data['count'] === 1 ? 'Meldung' : 'Meldungen' }}</span>
    @if($data['oldest'])
        <span class="wv-pill" title="Älteste eingereichte Meldung"><i data-feather="clock"></i>{{ $data['oldest']->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
    @endif
</div>
@if($data['people']->isEmpty())
    <x-dashboard.empty icon="check-circle">Alle Zeiten freigegeben.</x-dashboard.empty>
@else
    <div class="wv-people" @if($rows === 2) style="flex:1 1 auto;" @endif>
        @if($rows === 2)<p class="wv-label" style="margin:0;">Offen je Person</p>@endif
        @foreach($data['people']->take($rows === 2 ? 5 : 2) as $person)
            <div class="wv-person">
                <x-dashboard.avatar :user="$person['user']" :size="$rows === 2 ? 26 : 20" />
                <span class="wv-truncate">{{ $person['user']?->name ?? 'Unbekannt' }}@if($rows === 2) <span class="ops-muted">· {{ $person['entries'] }}</span>@endif</span>
                <span class="wv-person-bar"><span style="width:{{ max(4, round($person['seconds'] / $data['peopleMax'] * 100)) }}%;"></span></span>
                <span class="wv-person-val">{{ $hours($person['seconds']) }}</span>
            </div>
        @endforeach
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Zeiten öffnen →</a>
