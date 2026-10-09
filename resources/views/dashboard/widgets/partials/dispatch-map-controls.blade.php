@php
    $mapId = 'dispatch-map-'.$this->getId();
    $dateTargets = 'setDispatchMapDate,moveDispatchMapDate,resetDispatchMapDate';
@endphp
<div class="wv-dispatch-map__toolbar" role="group" aria-label="Dispositionskarte steuern">
    <time datetime="{{ $data['date'] }}" class="wv-dispatch-map__selected-date" title="{{ $data['dateLabel'] }}"><span class="wv-dispatch-map__date-full">{{ $data['dateLabel'] }}</span><span class="wv-dispatch-map__date-short" aria-hidden="true">{{ substr($data['dateLabel'], 0, 5) }}</span></time>
    <div class="wv-dispatch-map__triggers">
        <x-ui.dropdown.anchor-dropdown align="right" width="72" offset="6" dropdown-id="{{ $mapId }}-date" layer-group="{{ $mapId }}-controls" content-role="dialog" content-label="Dispositionstag auswählen" content-classes="rt-ops wv-dispatch-map__menu wv-dispatch-map__date-menu" x-on:dropdown-open="$nextTick(() => $refs.panel?.querySelector('input[type=date]')?.focus({ preventScroll: true }))">
            <x-slot:trigger>
                <button type="button" class="wv-dispatch-map__trigger" aria-label="Datum auswählen" title="Datum auswählen: {{ $data['dateLabel'] }}" x-on:keydown.arrow-down.prevent.stop="openDropdown(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}"><i data-feather="calendar" aria-hidden="true"></i></button>
            </x-slot:trigger>
            <x-slot:content>
                <div class="ops-field"><label for="{{ $mapId }}-date">Datum der Dispositionskarte</label><x-ui.forms.input id="{{ $mapId }}-date" type="date" value="{{ $data['date'] }}" min="1900-01-01" max="2100-12-31" wire:change="setDispatchMapDate($event.target.value)" x-on:change="close(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" /></div>
                <div class="wv-dispatch-map__date-actions">
                    <button type="button" wire:click="moveDispatchMapDate(-1)" x-on:click="close(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Vorheriger Tag"><i data-feather="chevron-left" aria-hidden="true"></i></button>
                    <button type="button" wire:click="resetDispatchMapDate" x-on:click="close(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}">Heute</button>
                    <button type="button" wire:click="moveDispatchMapDate(1)" x-on:click="close(true)" wire:loading.attr="disabled" wire:target="{{ $dateTargets }}" aria-label="Nächster Tag"><i data-feather="chevron-right" aria-hidden="true"></i></button>
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
