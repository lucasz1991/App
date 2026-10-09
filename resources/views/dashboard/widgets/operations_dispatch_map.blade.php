@php
    $mapId = 'dispatch-map-'.$this->getId();
    $dateTargets = 'setDispatchMapDate,moveDispatchMapDate,resetDispatchMapDate';
    $entryKinds = implode(' und ', array_filter([$data['canViewShifts'] ? 'Schichten' : null, $data['canViewInquiries'] ? 'Anfragen' : null]));
    [$mapLeft, $mapTop, $mapWidth, $mapHeight] = array_map('floatval', explode(' ', $data['viewBox']));
@endphp
<div class="wv-dispatch-map" data-dispatch-map data-dispatch-date="{{ $data['date'] }}" wire:key="dispatch-map-content-{{ $data['date'] }}" x-effect="if (day !== $el.dataset.dispatchDate) { kind = 'all'; place = 'all'; day = $el.dataset.dispatchDate }">
    @error('dispatchMapDate')<p class="ops-errors" role="alert">{{ $message }}</p>@enderror
    <div class="wv-dispatch-map__content" wire:loading.class="wv-dispatch-map__content--loading" wire:target="{{ $dateTargets }}">
        <figure class="wv-dispatch-map__figure">
            <div class="wv-dispatch-map__stage" style="aspect-ratio: {{ $mapWidth }} / {{ $mapHeight }}">
                <svg class="wv-dispatch-map__canvas" viewBox="{{ $data['viewBox'] }}" role="img" aria-labelledby="{{ $mapId }}-title {{ $mapId }}-description">
                    <title id="{{ $mapId }}-title">Einsatzorte in Deutschland am {{ $data['dateLabel'] }}</title>
                    <desc id="{{ $mapId }}-description">{{ count($data['markers']) }} ermittelte Ortslagen. Die Positionen sind ungefähr. Die Kartenpunkte öffnen Details zu den jeweiligen Standorten.</desc>
                    <path class="wv-dispatch-map__outline" d="{{ $data['mapPath'] }}" />
                </svg>
                @foreach($data['markers'] as $marker)
                    <x-ui.dropdown.anchor-dropdown class="wv-dispatch-map__point" style="left: {{ ($marker['x'] - $mapLeft) / $mapWidth * 100 }}%; top: {{ ($marker['y'] - $mapTop) / $mapHeight * 100 }}%" x-show="(place === 'all' || place === $el.querySelector('[data-dispatch-marker]').dataset.dispatchMarker) && (kind === 'all' || (kind === 'shift' && {{ $marker['shiftCount'] }} > 0) || (kind === 'inquiry' && {{ $marker['inquiryCount'] }} > 0))" align="left" width="80" offset="6" :open-on-hover="true" dropdown-id="{{ $mapId }}-point-{{ $loop->index }}" layer-group="{{ $mapId }}-controls" content-role="dialog" content-label="{{ $marker['place'] }}: Einsatzdetails" content-classes="rt-ops wv-dispatch-map__menu wv-dispatch-map__point-menu">
                        <x-slot:trigger>
                            <button type="button" class="wv-dispatch-map__marker" data-dispatch-marker="{{ $marker['key'] }}" aria-label="{{ $marker['place'] }}: {{ $marker['count'] }} {{ $marker['count'] === 1 ? 'Eintrag' : 'Einträge' }}. Details öffnen" x-on:keydown.arrow-down.prevent.stop="focusHoverPanel(); $nextTick(() => Array.from($refs.panel.querySelectorAll('[data-dispatch-point-entry]')).find(entry => getComputedStyle(entry).display !== 'none')?.focus({ preventScroll: true }))">
                                @if($marker['shiftCount'] > 0)<span class="wv-dispatch-map__shift" aria-hidden="true" x-show="kind !== 'inquiry'"></span>@endif
                                @if($marker['inquiryCount'] > 0)<span class="wv-dispatch-map__inquiry" aria-hidden="true" x-show="kind !== 'shift'"></span>@endif
                            </button>
                        </x-slot:trigger>
                        <x-slot:content>
                            <div class="wv-dispatch-map__point-head"><strong>{{ $marker['place'] }}</strong><span>{{ $data['dateLabel'] }} · Position ungefähr</span></div>
                            @foreach($marker['items'] as $entry)
                                @include('dashboard.widgets.partials.dispatch-map-entry', ['entry' => $entry, 'preview' => true])
                            @endforeach
                            @if($marker['detailsTruncated'])
                                <p class="wv-dispatch-map__point-note" x-show="kind === 'all'">{{ count($marker['items']) }} von {{ $marker['count'] }} Einträgen · Details über die Fachansicht öffnen.</p>
                                @if($marker['shiftCount'] > $marker['detailsPerType'])<p class="wv-dispatch-map__point-note" x-show="kind === 'shift'">{{ $marker['detailsPerType'] }} von {{ $marker['shiftCount'] }} Schichten · Details über die Fachansicht öffnen.</p>@endif
                                @if($marker['inquiryCount'] > $marker['detailsPerType'])<p class="wv-dispatch-map__point-note" x-show="kind === 'inquiry'">{{ $marker['detailsPerType'] }} von {{ $marker['inquiryCount'] }} Anfragen · Details über die Fachansicht öffnen.</p>@endif
                            @endif
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                @endforeach
            </div>
            <figcaption class="wv-dispatch-map__legend">@if($data['canViewShifts'])<span><i class="wv-dispatch-map__shift-key"></i>Schichten</span>@endif @if($data['canViewInquiries'])<span><i class="wv-dispatch-map__inquiry-key"></i>Anfragen</span>@endif</figcaption>
            @if($data['markers'] === [])<p class="wv-dispatch-map__map-empty">{{ $data['count'] === 0 ? 'Keine Einträge für diesen Tag.' : 'Keine Kartenpunkte für diesen Tag.' }}</p>@endif
            <p class="wv-dispatch-map__map-empty" x-cloak x-show="place === 'unlocated'">Einträge ohne ermittelten Kartenpunkt.</p>
        </figure>
        <div class="wv-dispatch-map__list" role="region" aria-label="{{ $entryKinds }} mit Standort" tabindex="0">
            @forelse($data['items'] as $entry)
                @include('dashboard.widgets.partials.dispatch-map-entry', ['entry' => $entry, 'preview' => false])
            @empty
                <p class="wv-dispatch-map__empty">Keine {{ $entryKinds }} für diesen Tag.</p>
            @endforelse
            @if($data['items'] !== [])
                <p class="wv-dispatch-map__empty" x-cloak x-show="!Array.from($el.parentElement.querySelectorAll('[data-dispatch-entry]')).some(entry => (kind === 'all' || kind === entry.dataset.dispatchEntry) && (place === 'all' || place === entry.dataset.dispatchLocationKey))">Keine Einträge für diesen Filter.</p>
            @endif
            @if($data['undatedCount'] > 0)<p class="wv-dispatch-map__point-note">{{ $data['undatedCount'] }} {{ $data['undatedCount'] === 1 ? 'Anfrage ohne Termin' : 'Anfragen ohne Termin' }}</p>@endif
            @if($data['truncated'])<p class="wv-dispatch-map__point-note">Liste: {{ $data['displayedCount'] }} von {{ $data['count'] }} · Karte zeigt alle ermittelten Orte.</p>@endif
        </div>
    </div>
    <div class="wv-dispatch-map__notes">
        <span wire:loading wire:target="{{ $dateTargets }}" role="status">Tag wird geladen …</span>
        <span wire:offline role="status">Keine Verbindung. Angezeigter Stand bleibt erhalten.</span>
    </div>
</div>
