{{-- Eigene Geraete namentlich mit Einsatzbereitschaft; Balken + Legende zeigen das Verhaeltnis. --}}
@if($data['stats']['available'] && $data['stats']['total'] > 0)
    @php
        $stats = $data['stats'];
        $total = max(1, $stats['total']);
        $states = ['ready' => ['ok', 'Bereit'], 'pending' => ['warn', 'Einrichtung offen'], 'blocked' => ['danger', 'Blockiert']];
    @endphp
    <div class="wv-inline">
        <span class="widget-primary-val">{{ $stats['total'] }}</span>
        <span class="widget-primary-lbl">{{ $stats['total'] === 1 ? 'eigenes Gerät' : 'eigene Geräte' }}</span>
        @if($stats['blocked'] > 0)
            <span class="wv-pill ops-tone-warn"><i data-feather="alert-triangle"></i>{{ $stats['blocked'] }} blockiert</span>
        @elseif($stats['ready'] === $stats['total'])
            <span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Alle bereit</span>
        @endif
    </div>
    <div class="widget-segment-bar" style="margin-top:10px;" role="img" aria-label="{{ $stats['ready'] }} bereit, {{ $stats['pending'] }} Einrichtung offen, {{ $stats['blocked'] }} blockiert">
        <span class="widget-segment-ok" style="width:{{ $stats['ready'] / $total * 100 }}%"></span>
        <span class="widget-segment-warn" style="width:{{ $stats['pending'] / $total * 100 }}%"></span>
        <span class="widget-segment-danger" style="width:{{ $stats['blocked'] / $total * 100 }}%"></span>
    </div>
    <div class="widget-detail wv-list">
        @foreach($data['devices']->take($rows === 2 ? 4 : 1) as $device)
            <a class="wv-item" href="{{ $data['href'] }}" wire:navigate>
                <span class="wv-item-ico ops-tone-neutral"><i data-feather="{{ $device['icon'] }}"></i></span>
                <span class="wv-item-main">
                    <span class="wv-truncate">{{ $device['name'] }}</span>
                    <small class="wv-truncate">{{ $states[$device['state']][1] }}@if($rows === 2 && $device['lastSeen']) · zuletzt gesehen {{ $device['lastSeen']->diffForHumans() }}@endif</small>
                </span>
                <span class="wv-status-dot" data-state="{{ $states[$device['state']][0] }}" title="{{ $states[$device['state']][1] }}"></span>
            </a>
        @endforeach
    </div>
    @if($data['href'])<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Meine Geräte öffnen →</a>@endif
@elseif($data['stats']['available'])
    <x-dashboard.empty icon="smartphone">Dir sind noch keine Geräte zugewiesen.</x-dashboard.empty>
    @if($data['href'])<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Meine Geräte öffnen →</a>@endif
@else
    <x-dashboard.empty icon="smartphone">Geräteverwaltung ist hier nicht verfügbar.</x-dashboard.empty>
@endif
