@php
    $mapId = 'dispatch-map-'.$this->getId();
    $dateTargets = 'setDispatchMapDate,moveDispatchMapDate,resetDispatchMapDate';
    $entryKinds = implode(' und ', array_filter([$data['canViewShifts'] ? 'Schichten' : null, $data['canViewInquiries'] ? 'Anfragen' : null]));
@endphp
<div class="wv-dispatch-map" data-dispatch-map data-dispatch-date="{{ $data['date'] }}" wire:key="dispatch-map-content-{{ $data['date'] }}" x-data="{ kind: 'all', place: 'all' }">
    <div class="wv-dispatch-map__toolbar" role="group" aria-label="Dispositionskarte steuern">
        <time datetime="{{ $data['date'] }}" class="wv-dispatch-map__selected-date">{{ $data['dateLabel'] }}</time>
        <div class="wv-dispatch-map__triggers">
            <x-ui.dropdown.anchor-dropdown align="right" width="72" offset="6" dropdown-id="{{ $mapId }}-date" layer-group="{{ $mapId }}-controls" content-role="dialog" content-label="Dispositionstag auswählen" content-classes="rt-ops wv-dispatch-map__menu wv-dispatch-map__date-menu" x-on:dropdown-open="$nextTick(() => $refs.panel?.querySelector('input[type=date]')?.focus({ preventScroll: true }))">
                <x-slot:trigger>
                    <button type="button" class="wv-dispatch-map__trigger" aria-label="Datum auswählen" title="Datum auswählen: {{ $data['dateLabel'] }}" x-on:keydown.arrow-down.prevent.stop="openDropdown(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}"><i data-feather="calendar" aria-hidden="true"></i></button>
                </x-slot:trigger>
                <x-slot:content>
                    <div class="ops-field"><label for="{{ $mapId }}-date">Datum der Dispositionskarte</label><x-ui.forms.input id="{{ $mapId }}-date" type="date" value="{{ $data['date'] }}" min="1900-01-01" max="2100-12-31" wire:change="setDispatchMapDate($event.target.value)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" /></div>
                    <div class="wv-dispatch-map__date-actions">
                        <button type="button" wire:click="moveDispatchMapDate(-1)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Vorheriger Tag"><i data-feather="chevron-left" aria-hidden="true"></i></button>
                        <button type="button" wire:click="resetDispatchMapDate" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}">Heute</button>
                        <button type="button" wire:click="moveDispatchMapDate(1)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Nächster Tag"><i data-feather="chevron-right" aria-hidden="true"></i></button>
                    </div>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
            <x-ui.dropdown.anchor-dropdown align="right" width="64" offset="6" dropdown-id="{{ $mapId }}-kind" layer-group="{{ $mapId }}-controls" content-role="dialog" content-label="Einträge anzeigen" content-classes="rt-ops wv-dispatch-map__menu" x-on:dropdown-open="$nextTick(() => $refs.panel?.querySelector('input:checked')?.focus({ preventScroll: true }))">
                <x-slot:trigger>
                    <button type="button" class="wv-dispatch-map__trigger" :class="{ 'is-filtered': kind !== 'all' }" aria-label="Einträge filtern" :title="kind === 'all' ? 'Alle Einträge' : (kind === 'shift' ? 'Schichten' : 'Anfragen')" x-on:keydown.arrow-down.prevent.stop="openDropdown(true)"><i data-feather="sliders" aria-hidden="true"></i></button>
                </x-slot:trigger>
                <x-slot:content>
                    <fieldset class="wv-dispatch-map__choices">
                        <legend>Einträge anzeigen</legend>
                        <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-kind-choice" value="all" x-model="kind" x-on:change="close(true)" data-dispatch-kind="all" /><span>Alle Einträge</span></label>
                        @if($data['canViewShifts'])
                            <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-kind-choice" value="shift" x-model="kind" x-on:change="close(true)" data-dispatch-kind="shift" /><span>Schichten</span><small>{{ $data['shiftsCount'] }}</small></label>
                        @endif
                        @if($data['canViewInquiries'])
                            <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-kind-choice" value="inquiry" x-model="kind" x-on:change="close(true)" data-dispatch-kind="inquiry" /><span>Anfragen</span><small>{{ $data['inquiriesCount'] }}</small></label>
                        @endif
                    </fieldset>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
            <x-ui.dropdown.anchor-dropdown align="right" width="72" offset="6" dropdown-id="{{ $mapId }}-place" layer-group="{{ $mapId }}-controls" content-role="dialog" content-label="Standort filtern" content-classes="rt-ops wv-dispatch-map__menu" x-on:dropdown-open="$nextTick(() => $refs.panel?.querySelector('input:checked')?.focus({ preventScroll: true }))">
                <x-slot:trigger>
                    <button type="button" class="wv-dispatch-map__trigger" :class="{ 'is-filtered': place !== 'all' }" aria-label="Standort auswählen" title="Standort filtern" x-on:keydown.arrow-down.prevent.stop="openDropdown(true)"><i data-feather="map-pin" aria-hidden="true"></i></button>
                </x-slot:trigger>
                <x-slot:content>
                    <fieldset class="wv-dispatch-map__choices">
                        <legend>Standort filtern</legend>
                        <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-place-choice" value="all" x-model="place" x-on:change="close(true)" data-dispatch-place="all" /><span>Alle Standorte</span></label>
                        @foreach($data['markers'] as $marker)
                            <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-place-choice" value="{{ $marker['key'] }}" x-model="place" x-on:change="close(true)" data-dispatch-place="{{ $marker['key'] }}" /><span>{{ $marker['place'] }}</span><small>{{ $marker['count'] }}</small></label>
                        @endforeach
                        @if($data['unlocatedCount'] > 0)
                            <label class="wv-dispatch-map__choice"><input type="radio" name="{{ $mapId }}-place-choice" value="unlocated" x-model="place" x-on:change="close(true)" data-dispatch-place="unlocated" /><span>Ohne Kartenpunkt</span><small>{{ $data['unlocatedCount'] }}</small></label>
                        @endif
                    </fieldset>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
        </div>
    </div>
    @error('dispatchMapDate')<p class="ops-errors" role="alert">{{ $message }}</p>@enderror
    <div class="wv-dispatch-map__summary" aria-live="polite">
        @if($data['canViewShifts'])<span><b>{{ $data['shiftsCount'] }}</b> {{ $data['shiftsCount'] === 1 ? 'Schicht' : 'Schichten' }}</span>@endif
        @if($data['canViewInquiries'])<span><b>{{ $data['inquiriesCount'] }}</b> {{ $data['inquiriesCount'] === 1 ? 'Anfrage' : 'Anfragen' }}</span>@endif
        <span class="wv-dispatch-map__unlocated"><b>{{ $data['unlocatedCount'] }}</b> ohne Kartenpunkt</span>
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
