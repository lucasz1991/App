<a class="wv-dispatch-map__entry" @if($preview ?? false) data-dispatch-point-entry="{{ $entry['type'] }}" @else data-dispatch-entry="{{ $entry['type'] }}" @endif data-dispatch-location-key="{{ $entry['locationKey'] }}" href="{{ $entry['href'] }}" wire:navigate x-show="(kind === 'all' || kind === @js($entry['type'])) && (place === 'all' || place === @js($entry['locationKey']))">
    <span class="wv-dispatch-map__entry-top"><span class="wv-dispatch-map__type" data-kind="{{ $entry['type'] }}">{{ $entry['type'] === 'shift' ? 'Schicht' : 'Anfrage' }}</span><span>{{ $entry['timeLabel'] }}</span></span>
    <strong>{{ $entry['title'] }}</strong>
    <span class="wv-dispatch-map__entry-location"><i data-feather="map-pin" aria-hidden="true"></i>{{ $entry['locationLabel'] ?: 'Kein Einsatzort hinterlegt' }}</span>
    <span class="wv-dispatch-map__entry-meta">
        {{ $entry['statusLabel'] }}
        @if($entry['type'] === 'shift')
            · {{ $entry['assignedStaff'] }}/{{ $entry['requiredStaff'] }} besetzt
        @endif
        @if(($entry['location']['state'] ?? '') !== 'located')
            · {{ $entry['location']['label'] }}
        @endif
    </span>
</a>
