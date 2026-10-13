<section class="rt-regions" aria-label="Einsatzgebiete" x-data="{}">
    @include('livewire.profile.partials.employee-regions-styles')
    <header class="rt-regions__header">
        <div><h2>Einsatzgebiete</h2><p>Wo Einsätze bevorzugt, möglich oder im Notfall vorgesehen sind.</p></div>
    </header>
    @if(!$ready)
        <p role="status" class="rt-regions__message">Einsatzgebiete sind noch nicht eingerichtet.</p>
    @else
    <form wire:submit="save">
        <div class="rt-regions__layout">
            <div class="rt-regions__visual">
                <div class="rt-regions__map-heading"><span>Deutschland</span><span>{{ count($selectedStates) }} {{ count($selectedStates) === 1 ? 'Bundesland' : 'Bundesländer' }}</span></div>
                <x-ui.germany-map :height="510" :interactive="true" label="Einsatzgebiete auf der Deutschlandkarte. Bundesland der gewählten Zone zuordnen." class="rt-regions__map">
                    <x-slot:markers>
                        <svg class="rt-regions__overlay" viewBox="0 0 1154 1363" aria-label="Bundesländer auswählen" role="group">
                            <defs aria-hidden="true"><clipPath id="region-outline-{{ $this->getId() }}">@foreach($statePaths as $code => $path)<use href="#region-shape-{{ $this->getId() }}-{{ $code }}" />@endforeach</clipPath></defs>
                            @foreach($statePaths as $code => $path)
                                @php($zone = $selectedStates[$code] ?? 'none')
                                <path id="region-shape-{{ $this->getId() }}-{{ $code }}" d="{{ $path }}" fill-rule="evenodd" class="rt-regions__state" data-zone="{{ $zone }}" role="button" tabindex="0" aria-label="{{ $states[$code] }}: {{ $zones[$zone]['label'] ?? 'Nicht zugeordnet' }}" aria-pressed="{{ $zone === $activeZone ? 'true' : 'false' }}"
                                    wire:key="region-state-{{ $code }}" wire:click="toggleState('{{ $code }}')" x-on:keydown.enter.prevent="$wire.toggleState('{{ $code }}')" x-on:keydown.space.prevent="$wire.toggleState('{{ $code }}')">
                                    <title>{{ $states[$code] }} · {{ $zones[$zone]['label'] ?? 'Nicht zugeordnet' }}</title>
                                </path>
                            @endforeach
                            <g aria-hidden="true" class="rt-regions__radii" clip-path="url(#region-outline-{{ $this->getId() }})">
                                @foreach($rings as $zone => $path)<path d="{{ $path }}" data-zone="{{ $zone }}" />@endforeach
                                @if($basePoint)<circle cx="{{ $basePoint['x'] }}" cy="{{ $basePoint['y'] }}" r="11" class="rt-regions__origin" />@endif
                            </g>
                        </svg>
                    </x-slot:markers>
                </x-ui.germany-map>
            </div>
            <div class="rt-regions__editor">
                <div class="rt-regions__zones" role="group" aria-label="Zone für die Bundesländerauswahl">
                    @foreach($zones as $zone => $definition)
                    <button type="button" wire:click="selectZone('{{ $zone }}')" data-zone="{{ $zone }}" aria-pressed="{{ $activeZone === $zone ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="selectZone,toggleState"><span class="rt-regions__zone-name"><i class="rt-regions__zone-dot" aria-hidden="true"></i>{{ $definition['label'] }}</span><small><strong>{{ $definition['score'] }}</strong> Eignung</small></button>
                    @endforeach
                </div>
                <fieldset class="rt-regions__radius"><legend>Radius ab Bezugsort <span>optional</span></legend>
                    <label for="region-origin-{{ $userId }}" class="sr-only">Bezugsort</label>
                    <div class="rt-regions__origin-field"><i class="far fa-map-marker-alt" aria-hidden="true"></i><input id="region-origin-{{ $userId }}" type="text" wire:model.live.blur="preferences.base_location" placeholder="Bezugsort, z. B. Hamburg" maxlength="200" autocomplete="off" aria-describedby="region-distance-note-{{ $userId }}"></div>
                    <div class="rt-regions__radius-fields">
                    @foreach($zones as $zone => $definition)
                        <label data-zone="{{ $zone }}" for="region-radius-{{ $userId }}-{{ $zone }}"><span>{{ $definition['label'] }}</span><span class="rt-regions__unit"><input id="region-radius-{{ $userId }}-{{ $zone }}" type="number" min="1" max="{{ $zone === 'preferred' ? 1000 : 2000 }}" wire:model.live.blur="preferences.{{ $definition['radius'] }}" @if($zone === 'emergency') placeholder="–" @endif><span>km</span></span></label>
                    @endforeach
                    </div>
                    <p id="region-distance-note-{{ $userId }}" class="rt-regions__hint">Luftlinie zur Ortsmitte. Bundesländer und Radien ergänzen sich; die passendste Zone zählt.</p>
                    @if(trim((string) ($preferences['base_location'] ?? '')) !== '' && !$basePoint)<p class="rt-regions__invalid" role="status">Bezugsort nicht eindeutig erkannt.</p>@endif
                </fieldset>
                <fieldset class="rt-regions__states"><legend>Bundesländer <span class="rt-regions__current-zone" data-zone="{{ $activeZone }}"><i class="rt-regions__zone-dot" aria-hidden="true"></i>{{ $zones[$activeZone]['label'] ?? 'Zone wählen' }}</span></legend>
                    <div class="rt-regions__state-list">
                    @foreach($states as $code => $label)
                        @php($zone = $selectedStates[$code] ?? 'none')
                        <button type="button" wire:click="toggleState('{{ $code }}')" data-zone="{{ $zone }}" aria-pressed="{{ $zone === $activeZone ? 'true' : 'false' }}" aria-label="{{ $label }}: {{ $zones[$zone]['label'] ?? 'Nicht zugeordnet' }}" wire:key="region-choice-{{ $code }}" wire:loading.attr="disabled" wire:target="toggleState"><i class="far {{ $zone === 'none' ? 'fa-plus' : 'fa-check' }}" aria-hidden="true"></i><span>{{ $label }}</span>@if($zone !== 'none')<span class="sr-only">{{ $zones[$zone]['label'] }}</span>@endif</button>
                    @endforeach
                    </div>
                </fieldset>
                <details class="rt-regions__exclusions"><summary>Ausgeschlossene Gebiete <span>{{ count($preferences['no_go_areas'] ?? []) }}</span></summary>
                    <div class="rt-regions__exclusion-content"><p class="rt-regions__hint">Diese Gebiete bleiben auch im Notfall gesperrt.</p>
                    @foreach($preferences['no_go_areas'] ?? [] as $index => $area)
                        <div class="rt-regions__exclusion" wire:key="region-exclusion-{{ $index }}"><label><span class="sr-only">Ausgeschlossener Ort {{ $index + 1 }}</span><input type="text" wire:model="preferences.no_go_areas.{{ $index }}.location" placeholder="Ort" maxlength="200"></label><label><span class="sr-only">Ausschlussradius {{ $index + 1 }} in km</span><input type="number" min="1" max="1000" wire:model="preferences.no_go_areas.{{ $index }}.radius_km" aria-label="Ausschlussradius {{ $index + 1 }} in km"></label><button type="button" wire:click="removeExclusion({{ $index }})" aria-label="Ausschluss {{ $index + 1 }} entfernen"><i class="far fa-trash-can" aria-hidden="true"></i></button></div>
                    @endforeach
                    <button type="button" wire:click="addExclusion" class="rt-regions__text-button"><i class="far fa-plus" aria-hidden="true"></i> Ausschluss hinzufügen</button></div>
                </details>
            </div>
        </div>
        <footer class="rt-regions__footer">
            <div class="rt-regions__feedback" role="status" aria-live="polite">
                @if($errors->any())<ul class="rt-regions__invalid">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @elseif($notice)<span class="rt-regions__success"><i class="far fa-check-circle" aria-hidden="true"></i> {{ $notice }}</span>
                @elseif($dirty)<span>Ungespeicherte Änderungen</span>
                @else<span>Außerhalb der Zonen: geringere Eignung. Fahrzeit und Ruhezeit werden separat geprüft.</span>@endif
            </div>
            <div class="rt-regions__actions"><button type="button" wire:click="reload" wire:loading.attr="disabled" class="rt-regions__reset">Verwerfen</button><button type="submit" class="rt-regions__save" wire:loading.attr="disabled"><i class="far fa-check" aria-hidden="true" wire:loading.remove wire:target="save"></i><i class="far fa-spinner fa-spin" aria-hidden="true" wire:loading wire:target="save"></i> Speichern</button></div>
        </footer>
    </form>
    @endif
</section>
