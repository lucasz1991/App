{{-- Kalender-Heatmap: wie viele Personen an den naechsten 14 Tagen fehlen (genehmigt + beantragt). --}}
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['count'] }}</span>
    <span class="widget-primary-lbl">{{ $data['count'] === 1 ? 'Antrag offen' : 'Anträge offen' }}</span>
    <span class="wv-pill {{ $data['absentToday'] > 0 ? 'ops-tone-warn' : '' }}" title="{{ $data['absentToday'] }} heute abwesend"><i data-feather="user-x"></i>{{ $data['absentToday'] }} heute</span>
</div>
<div class="wv-heat" role="img" aria-label="Abwesende Personen je Tag in den nächsten 14 Tagen">
    @foreach($data['strip'] as $day)
        @php($heat = round($day['count'] / $data['stripMax'], 2))
        <span
            class="wv-heat-cell"
            style="--heat:{{ $heat }};"
            @if($day['today']) data-today @endif
            @if($day['weekend'] && $day['count'] === 0) data-weekend @endif
            @if($heat >= .5) data-strong @endif
            title="{{ $day['weekday'] }} {{ $day['date'] }}: {{ $day['count'] }} abwesend"
        >@if($day['count'] > 0)<b>{{ $day['count'] }}</b>@endif</span>
    @endforeach
</div>
<div class="wv-heat-labels" aria-hidden="true">
    @foreach($data['strip'] as $day)<span>{{ mb_substr($day['weekday'], 0, 1) }}</span>@endforeach
</div>
@if($rows === 2)
    <div class="widget-detail">
        <p class="wv-label">Zu entscheiden</p>
        <div class="wv-list">
            @forelse($data['items']->take(3) as $item)
                <div class="wv-item">
                    <span class="wv-item-ico ops-tone-neutral"><i data-feather="calendar"></i></span>
                    <span class="wv-item-main"><span class="wv-truncate">{{ $item['title'] }}</span><small class="wv-truncate">{{ $item['meta'] }} · {{ $item['range'] }}</small></span>
                </div>
            @empty
                <x-dashboard.empty icon="check-circle">Keine offenen Anträge.</x-dashboard.empty>
            @endforelse
        </div>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Abwesenheiten öffnen →</a>
