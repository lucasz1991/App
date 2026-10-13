<a class="wv-dispatch-map__entry" @if($preview ?? false) data-dispatch-point-entry="{{ $entry['type'] }}" @else data-dispatch-entry="{{ $entry['type'] }}" @endif data-dispatch-row-key="{{ $entry['key'] }}" data-dispatch-location-key="{{ $entry['locationKey'] }}" data-dispatch-location-keys="{{ json_encode($entry['locationKeys'], JSON_THROW_ON_ERROR) }}" x-on:pointerenter="if ($event.pointerType !== 'touch') mapHover($el.dataset.dispatchRowKey, JSON.parse($el.dataset.dispatchLocationKeys))" x-on:pointerleave="mapUnhover($el.dataset.dispatchRowKey)" x-on:pointercancel="mapUnhover($el.dataset.dispatchRowKey)" x-on:focusin="mapFocus($el.dataset.dispatchRowKey, JSON.parse($el.dataset.dispatchLocationKeys))" x-on:focusout="mapBlur($el.dataset.dispatchRowKey)" href="{{ $entry['href'] }}" wire:navigate x-show="(kind === 'all' || kind === @js($entry['type'])) && mapMatchesPlace(place, JSON.parse($el.dataset.dispatchLocationKeys))">
    <span class="wv-dispatch-map__entry-top"><span class="wv-dispatch-map__type" data-kind="{{ $entry['type'] }}">{{ $entry['type'] === 'shift' ? 'Schicht' : 'Anfrage' }}</span><span>{{ $entry['timeLabel'] }}</span></span>
    <strong>{{ $entry['title'] }}</strong>
    <span class="wv-dispatch-map__entry-location" @if(($entry['originalLocationLabel'] ?? '') !== '' && $entry['originalLocationLabel'] !== $entry['locationLabel']) title="Hinterlegt: {{ $entry['originalLocationLabel'] }}" @endif><i data-feather="map-pin" aria-hidden="true"></i>{{ $entry['locationLabel'] ?: 'Kein Einsatzort hinterlegt' }}</span>
    <span class="wv-dispatch-map__entry-meta">
        {{ $entry['statusLabel'] }}
        @if($entry['type'] === 'shift')
            · {{ $entry['assignedStaff'] }}/{{ $entry['requiredStaff'] }} besetzt
        @endif
        @if(filled($entry['location']['label'] ?? null))
            · {{ $entry['location']['label'] }}
        @endif
    </span>
</a>
