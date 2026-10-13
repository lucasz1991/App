<section class="rt-regions" aria-label="Einsatzgebiete"
    x-data="{
        mode: 'states', exclusionsOpen: false, openedState: null, picker: '', fromPicker: false,
        menuX: 12, menuY: 12, busy: false, names: @js($states),
        openRegion(code, event) {
            if (!this.names[code]) return;
            this.fromPicker = event.currentTarget.tagName === 'SELECT';
            this.openedState = code;
            this.exclusionsOpen = false;
            const canvas = this.$refs.canvas.getBoundingClientRect();
            const target = event.currentTarget.getBoundingClientRect();
            const pointer = event.type === 'click' && event.detail > 0;
            const x = (pointer ? event.clientX : target.left + target.width / 2) - canvas.left;
            const y = (pointer ? event.clientY : target.bottom) - canvas.top;
            this.$nextTick(() => {
                const panel = this.$refs.regionMenu.getBoundingClientRect();
                this.menuX = Math.max(12, Math.min(x + 10, canvas.width - panel.width - 12));
                const top = Math.max(12, -canvas.top + 12);
                const bottom = Math.min(canvas.height - panel.height - 12, window.innerHeight - canvas.top - panel.height - 12);
                this.menuY = Math.max(12, Math.min(Math.max(top, Math.min(y + 10, bottom)), canvas.height - panel.height - 12));
                this.$refs.regionMenu.querySelector('[data-choice]').focus({ preventScroll: true });
            });
        },
        currentZone() {
            return Object.keys(this.$wire.preferences.federal_states).find(zone => this.$wire.preferences.federal_states[zone].includes(this.openedState)) || null;
        },
        closeRegion(restoreFocus = true) {
            const code = this.openedState;
            this.openedState = null;
            this.picker = '';
            if (restoreFocus && code) this.$nextTick(() => (this.fromPicker ? this.$refs.statePicker : this.$root.querySelector('[data-state-code=' + code + ']'))?.focus({ preventScroll: true }));
        },
        async chooseRegion(zone) {
            if (this.busy || !this.openedState) return;
            this.busy = true;
            try { await this.$wire.assignState(this.openedState, zone); this.closeRegion(); }
            finally { this.busy = false; }
        },
        changeMode(next) { this.closeRegion(false); this.exclusionsOpen = false; this.mode = next; },
        moveChoice(step) {
            const choices = [...this.$refs.regionMenu.querySelectorAll('[data-choice]:not(:disabled)')];
            const index = choices.indexOf(document.activeElement);
            choices[(index + step + choices.length) % choices.length]?.focus();
        }
    }"
    x-on:keydown.escape.stop="if (openedState) closeRegion(); else if (exclusionsOpen) { exclusionsOpen = false; $refs.exclusionToggle.focus(); }"
    x-on:resize.window="closeRegion(false)">
    @include('livewire.profile.partials.employee-regions-styles')
    <header class="rt-regions__header"><h2>Einsatzgebiete</h2></header>
    @if(!$ready)
        <p role="status" class="rt-regions__message">Einsatzgebiete sind noch nicht eingerichtet.</p>
    @else
    <form wire:submit="save">
        <div class="rt-regions__canvas" x-ref="canvas" x-bind:data-mode="mode">
            <div class="rt-regions__toolbar">
                <div class="rt-regions__modes" role="group" aria-label="Bearbeitungsmodus">
                    <button type="button" x-on:click="changeMode('states')" x-bind:aria-pressed="mode === 'states'"><i class="far fa-map" aria-hidden="true"></i><span>Bundesländer</span>@if(count($selectedStates))<small>{{ count($selectedStates) }}</small>@endif</button>
                    <button type="button" x-on:click="changeMode('radius')" x-bind:aria-pressed="mode === 'radius'"><i class="far fa-map-marker-alt" aria-hidden="true"></i><span>Standort &amp; Radius</span>@if($basePoint)<i class="rt-regions__configured" aria-label="Eingestellt"></i>@endif</button>
                </div>
                <div class="rt-regions__tools">
                    <div class="rt-regions__state-picker" x-show="mode === 'states'">
                        <label for="region-picker-{{ $userId }}" class="sr-only">Bundesland auswählen</label>
                        <select id="region-picker-{{ $userId }}" x-ref="statePicker" x-model="picker" x-on:change="openRegion($event.target.value, $event)" aria-haspopup="dialog" aria-controls="region-menu-{{ $userId }}" x-bind:aria-expanded="openedState !== null && fromPicker">
                            <option value="">Bundesland wählen</option>
                            @foreach($states as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <button type="button" class="rt-regions__tool" x-ref="exclusionToggle" x-on:click="closeRegion(false); exclusionsOpen = !exclusionsOpen" x-bind:aria-expanded="exclusionsOpen" aria-controls="region-exclusions-{{ $userId }}" aria-label="Ausgeschlossene Gebiete bearbeiten" title="Ausgeschlossene Gebiete"><i class="far fa-ban" aria-hidden="true"></i>@if(count($preferences['no_go_areas'] ?? []))<small>{{ count($preferences['no_go_areas']) }}</small>@endif</button>
                </div>
            </div>
            <x-ui.germany-map :height="580" :interactive="true" label="Einsatzgebiete auf der Deutschlandkarte. Bundesland auswählen und eine Zone zuordnen." class="rt-regions__map">
                <x-slot:markers>
                    <svg class="rt-regions__overlay" viewBox="0 0 1154 1363" aria-label="Bundesländer auswählen" role="group">
                        <defs aria-hidden="true"><clipPath id="region-outline-{{ $this->getId() }}">@foreach($statePaths as $code => $path)<use href="#region-shape-{{ $this->getId() }}-{{ $code }}" />@endforeach</clipPath></defs>
                        @foreach($statePaths as $code => $path)
                            @php($zone = $selectedStates[$code] ?? 'none')
                            <path id="region-shape-{{ $this->getId() }}-{{ $code }}" d="{{ $path }}" fill-rule="evenodd" class="rt-regions__state" data-zone="{{ $zone }}" data-state-code="{{ $code }}" role="button" tabindex="0" aria-label="{{ $states[$code] }}: {{ $zones[$zone]['label'] ?? 'Nicht zugeordnet' }}" aria-haspopup="dialog" aria-controls="region-menu-{{ $userId }}" x-bind:aria-expanded="openedState === '{{ $code }}'" x-bind:data-open="openedState === '{{ $code }}'"
                                wire:key="region-state-{{ $code }}" x-on:click.stop="openRegion('{{ $code }}', $event)" x-on:keydown.enter.prevent.stop="openRegion('{{ $code }}', $event)" x-on:keydown.space.prevent.stop="openRegion('{{ $code }}', $event)">
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
            <div id="region-menu-{{ $userId }}" class="rt-regions__region-menu" x-ref="regionMenu" x-cloak x-show="openedState !== null" x-bind:style="{ left: menuX + 'px', top: menuY + 'px' }" role="dialog" x-bind:aria-label="'Einsatzgebiet für ' + (names[openedState] || '')" x-on:click.outside="if (openedState) closeRegion(false)" x-on:keydown.arrow-down.prevent="moveChoice(1)" x-on:keydown.arrow-up.prevent="moveChoice(-1)">
                <div class="rt-regions__popover-title"><strong x-text="names[openedState] || ''"></strong><button type="button" x-on:click="closeRegion()" aria-label="Bundeslandauswahl schließen"><i class="far fa-times" aria-hidden="true"></i></button></div>
                @foreach($zones as $zone => $definition)
                    <button type="button" data-choice data-zone="{{ $zone }}" x-on:click="chooseRegion('{{ $zone }}')" x-bind:aria-pressed="currentZone() === '{{ $zone }}'" x-bind:disabled="busy"><i class="rt-regions__zone-dot" aria-hidden="true"></i><span>{{ $definition['label'] }}</span><i class="far fa-check rt-regions__choice-check" aria-hidden="true" x-show="currentZone() === '{{ $zone }}'"></i></button>
                @endforeach
                <button type="button" data-choice class="rt-regions__remove-state" x-on:click="chooseRegion(null)" x-bind:disabled="busy || currentZone() === null"><i class="far fa-trash-alt" aria-hidden="true"></i><span>Zuordnung entfernen</span></button>
            </div>
            <div class="rt-regions__radius-panel" x-cloak x-show="mode === 'radius' && !exclusionsOpen">
                <div class="rt-regions__base-field"><label for="region-origin-{{ $userId }}">Standort</label><div class="rt-regions__origin-field"><i class="far fa-map-marker-alt" aria-hidden="true"></i><input id="region-origin-{{ $userId }}" type="text" wire:model.live.blur="preferences.base_location" placeholder="Ort, z. B. Hamburg" maxlength="200" autocomplete="off" aria-describedby="region-distance-note-{{ $userId }}"></div></div>
                <fieldset class="rt-regions__radius-fields"><legend class="sr-only">Radius je Zone</legend>
                    @foreach($zones as $zone => $definition)
                    <label data-zone="{{ $zone }}" for="region-radius-{{ $userId }}-{{ $zone }}"><span>{{ $definition['label'] }}</span><span class="rt-regions__unit"><input id="region-radius-{{ $userId }}-{{ $zone }}" type="number" min="1" max="{{ $zone === 'preferred' ? 1000 : 2000 }}" wire:model.live.blur="preferences.{{ $definition['radius'] }}" @if($zone === 'emergency') placeholder="–" @endif><span>km</span></span></label>
                    @endforeach
                </fieldset>
                <p id="region-distance-note-{{ $userId }}" class="rt-regions__hint">Luftlinie. Radien und Bundesländer gelten gemeinsam.</p>
                @if(trim((string) ($preferences['base_location'] ?? '')) !== '' && !$basePoint)<p class="rt-regions__invalid" role="status">Standort nicht eindeutig erkannt.</p>@endif
            </div>
            <div id="region-exclusions-{{ $userId }}" class="rt-regions__exclusions" x-cloak x-show="exclusionsOpen" role="region" aria-label="Ausgeschlossene Gebiete">
                <div class="rt-regions__popover-title"><strong>Ausgeschlossene Gebiete</strong><button type="button" x-on:click="exclusionsOpen = false; $refs.exclusionToggle.focus()" aria-label="Ausgeschlossene Gebiete schließen"><i class="far fa-times" aria-hidden="true"></i></button></div>
                <p class="rt-regions__hint">Diese Gebiete bleiben auch im Notfall gesperrt.</p>
                @foreach($preferences['no_go_areas'] ?? [] as $index => $area)
                    <div class="rt-regions__exclusion" wire:key="region-exclusion-{{ $index }}"><label><span class="sr-only">Ausgeschlossener Ort {{ $index + 1 }}</span><input type="text" wire:model="preferences.no_go_areas.{{ $index }}.location" placeholder="Ort" maxlength="200"></label><label><span class="sr-only">Ausschlussradius {{ $index + 1 }} in km</span><input type="number" min="1" max="1000" wire:model="preferences.no_go_areas.{{ $index }}.radius_km" aria-label="Ausschlussradius {{ $index + 1 }} in km"></label><button type="button" wire:click="removeExclusion({{ $index }})" aria-label="Ausschluss {{ $index + 1 }} entfernen"><i class="far fa-trash-alt" aria-hidden="true"></i></button></div>
                @endforeach
                <button type="button" wire:click="addExclusion" class="rt-regions__text-button"><i class="far fa-plus" aria-hidden="true"></i> Ausschluss hinzufügen</button>
            </div>
        </div>
        <footer class="rt-regions__footer">
            <button type="button" class="rt-regions__clear" wire:click="clearPreferences" x-on:click="closeRegion(false)" wire:loading.attr="disabled"><i class="far fa-trash-alt" aria-hidden="true"></i><span>Einstellungen löschen</span></button>
            <div class="rt-regions__feedback" role="status" aria-live="polite">
                @if($errors->any())<ul class="rt-regions__invalid">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @elseif($notice)<span @class(['rt-regions__success' => !$dirty])>{{ $notice }}</span>
                @elseif($dirty)<span>Ungespeicherte Änderungen</span>@endif
            </div>
            <div class="rt-regions__actions"><button type="button" wire:click="reload" x-on:click="closeRegion(false)" wire:loading.attr="disabled" class="rt-regions__reset">Verwerfen</button><button type="submit" class="rt-regions__save" wire:loading.attr="disabled"><i class="far fa-check" aria-hidden="true" wire:loading.remove wire:target="save"></i><i class="far fa-spinner fa-spin" aria-hidden="true" wire:loading wire:target="save"></i> Speichern</button></div>
        </footer>
    </form>
    @endif
</section>
