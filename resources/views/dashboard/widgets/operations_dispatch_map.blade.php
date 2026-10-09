@php
    $mapId = 'dispatch-map-'.$this->getId();
    $dateTargets = 'setDispatchMapDate,moveDispatchMapDate,resetDispatchMapDate';
    $entryKinds = implode(' und ', array_filter([$data['canViewShifts'] ? 'Schichten' : null, $data['canViewInquiries'] ? 'Anfragen' : null]));
@endphp
<div class="wv-dispatch-map" data-dispatch-map data-dispatch-date="{{ $data['date'] }}" wire:key="dispatch-map-content-{{ $data['date'] }}" x-data="{ kind: 'all', place: 'all' }">
    <div class="wv-dispatch-map__date" aria-label="Dispositionstag">
        <button type="button" class="ops-btn wv-dispatch-map__day" wire:click="moveDispatchMapDate(-1)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Vorheriger Tag"><i data-feather="chevron-left" aria-hidden="true"></i></button>
        <label class="wv-dispatch-map__date-input" for="{{ $mapId }}-date">
            <span class="sr-only">Datum der Dispositionskarte</span>
            <x-ui.forms.input id="{{ $mapId }}-date" type="date" value="{{ $data['date'] }}" min="1900-01-01" max="2100-12-31" wire:change="setDispatchMapDate($event.target.value)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" />
        </label>
        <button type="button" class="ops-btn wv-dispatch-map__day" wire:click="moveDispatchMapDate(1)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Nächster Tag"><i data-feather="chevron-right" aria-hidden="true"></i></button>
        <button type="button" class="ops-btn wv-dispatch-map__today" wire:click="resetDispatchMapDate" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}">Heute</button>
    </div>
    @error('dispatchMapDate')<p class="ops-errors" role="alert">{{ $message }}</p>@enderror
    <div class="wv-dispatch-map__summary" aria-live="polite">
        @if($data['canViewShifts'])<span><b>{{ $data['shiftsCount'] }}</b> {{ $data['shiftsCount'] === 1 ? 'Schicht' : 'Schichten' }}</span>@endif
        @if($data['canViewInquiries'])<span><b>{{ $data['inquiriesCount'] }}</b> {{ $data['inquiriesCount'] === 1 ? 'Anfrage' : 'Anfragen' }}</span>@endif
        <span class="wv-dispatch-map__unlocated"><b>{{ $data['unlocatedCount'] }}</b> ohne Kartenpunkt</span>
    </div>
    <div class="wv-dispatch-map__filters">
        <label for="{{ $mapId }}-kind"><span class="sr-only">Einträge anzeigen</span><select id="{{ $mapId }}-kind" x-model="kind"><option value="all">Alle Einträge</option>@if($data['canViewShifts'])<option value="shift">Schichten</option>@endif @if($data['canViewInquiries'])<option value="inquiry">Anfragen</option>@endif</select></label>
        <label for="{{ $mapId }}-place"><span class="sr-only">Standort filtern</span><select id="{{ $mapId }}-place" x-model="place"><option value="all">Alle Standorte</option>@foreach($data['markers'] as $marker)<option value="{{ $marker['key'] }}">{{ $marker['place'] }} ({{ $marker['count'] }})</option>@endforeach @if($data['unlocatedCount'] > 0)<option value="unlocated">Ohne Kartenpunkt</option>@endif</select></label>
    </div>
    <div class="wv-dispatch-map__content" wire:loading.class="wv-dispatch-map__content--loading" wire:target="{{ $dateTargets }}">
        <figure class="wv-dispatch-map__figure">
            <svg class="wv-dispatch-map__canvas" viewBox="{{ $data['viewBox'] }}" role="img" aria-labelledby="{{ $mapId }}-title {{ $mapId }}-description">
                <title id="{{ $mapId }}-title">Einsatzorte in Deutschland am {{ $data['dateLabel'] }}</title>
                <desc id="{{ $mapId }}-description">{{ count($data['markers']) }} ermittelte Ortslagen. @if($data['canViewShifts'])Kreise zeigen Schichten. @endif @if($data['canViewInquiries'])Quadrate zeigen Anfragen. @endif Die Einträge mit Standort stehen neben der Karte. Alle Positionen sind ungefähr.</desc>
                <path class="wv-dispatch-map__outline" d="{{ $data['mapPath'] }}" />
                @foreach($data['markers'] as $marker)
                    <g class="wv-dispatch-map__marker" transform="translate({{ $marker['x'] }} {{ $marker['y'] }})" data-dispatch-marker="{{ $marker['key'] }}" :class="{ 'is-muted': (place !== 'all' && place !== @js($marker['key'])) || (kind === 'shift' && {{ $marker['shiftCount'] }} === 0) || (kind === 'inquiry' && {{ $marker['inquiryCount'] }} === 0) }">
                        <title>{{ $marker['place'] }}: @if($data['canViewShifts']){{ $marker['shiftCount'] }} Schichten @endif @if($data['canViewInquiries']){{ $marker['inquiryCount'] }} Anfragen @endif</title>
                        <circle class="wv-dispatch-map__halo" r="7" />
                        @if($marker['shiftCount'] > 0)<circle class="wv-dispatch-map__shift" r="3.5" />@endif
                        @if($marker['inquiryCount'] > 0)<rect class="wv-dispatch-map__inquiry" x="{{ $marker['shiftCount'] > 0 ? 2 : -3 }}" y="-3" width="6" height="6" rx="1" />@endif
                    </g>
                @endforeach
            </svg>
            <figcaption class="wv-dispatch-map__legend">@if($data['canViewShifts'])<span><i class="wv-dispatch-map__shift-key"></i>Schichten</span>@endif @if($data['canViewInquiries'])<span><i class="wv-dispatch-map__inquiry-key"></i>Anfragen</span>@endif</figcaption>
        </figure>
        <div class="wv-dispatch-map__list" role="region" aria-label="{{ $entryKinds }} mit Standort" tabindex="0">
            @forelse($data['items'] as $entry)
                <a class="wv-dispatch-map__entry" data-dispatch-entry="{{ $entry['type'] }}" data-dispatch-location-key="{{ $entry['locationKey'] }}" href="{{ $entry['href'] }}" wire:navigate x-show="(kind === 'all' || kind === @js($entry['type'])) && (place === 'all' || place === @js($entry['locationKey']))">
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
            @empty
                <p class="wv-dispatch-map__empty">Keine {{ $entryKinds }} für diesen Tag.</p>
            @endforelse
            @if($data['items'] !== [])
                <p class="wv-dispatch-map__empty" x-cloak x-show="!Array.from($el.parentElement.querySelectorAll('[data-dispatch-entry]')).some(entry => (kind === 'all' || kind === entry.dataset.dispatchEntry) && (place === 'all' || place === entry.dataset.dispatchLocationKey))">Keine Einträge für diesen Filter.</p>
            @endif
        </div>
    </div>
    <div class="wv-dispatch-map__notes">
        <span>Ortslagen · ungefähr</span>
        @if($data['undatedCount'] > 0)<span>{{ $data['undatedCount'] }} {{ $data['undatedCount'] === 1 ? 'Anfrage ohne Termin' : 'Anfragen ohne Termin' }}</span>@endif
        @if($data['truncated'])<span>Liste: {{ $data['displayedCount'] }} von {{ $data['shiftsCount'] + $data['inquiriesCount'] }} · Karte zeigt alle ermittelten Orte.</span>@endif
        <span wire:loading wire:target="{{ $dateTargets }}" role="status">Tag wird geladen …</span>
        <span wire:offline role="status">Keine Verbindung. Angezeigter Stand bleibt erhalten.</span>
    </div>
</div>
