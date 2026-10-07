<section class="rt-staffing-section rt-staffing-editor" aria-label="Einsatzgebiete bearbeiten" wire:key="regional-editor-{{ $regionalEmployeeId }}" tabindex="-1" x-data x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'start', behavior: 'auto' }); })">
    <div class="rt-staffing-heading"><div><h3>Einsatzgebiete</h3><p>Persönliche Präferenzen · optional</p></div><button type="button" class="rt-ops-panel__icon-button" wire:click="closeRegionalPreference" aria-label="Gebietseinstellungen schließen"><i class="far fa-xmark" aria-hidden="true"></i></button></div>
    <x-user.person-anchor-preview :user="$regionalEmployee" :show-presence="false" :show-email="false" :size="8" />
    <x-ui.forms.checkbox id="staffing-region-enabled" wire:model.live="regionalPreference.enabled" label="Regionale Präferenzen verwenden" :toggle="true" />
    <p class="rt-staffing-note">Radien beziehen sich auf die Luftlinie zur Ortsmitte, nicht auf Fahrstrecken oder Fahrzeiten. Ohne Präferenz entsteht kein Nachteil. Unklare Orte werden nicht geraten.</p>
    @if($regionalPreference['enabled'] ?? false)
        <div class="rt-staffing-filters">
            <div class="rt-staffing-field-wide"><x-ui.forms.label for="staffing-base-location" value="Bezugsort (Stadt oder eindeutiger Ort)" /><x-ui.forms.input id="staffing-base-location" wire:model="regionalPreference.base_location" placeholder="z. B. München" maxlength="180" /></div>
            <div><x-ui.forms.label for="staffing-preferred-radius" value="Wunschgebiet bis (km)" /><x-ui.forms.input id="staffing-preferred-radius" type="number" min="1" max="1000" wire:model="regionalPreference.preferred_radius_km" /></div>
            <div><x-ui.forms.label for="staffing-border-radius" value="Grenzgebiet bis (km)" /><x-ui.forms.input id="staffing-border-radius" type="number" min="1" max="1000" wire:model="regionalPreference.border_radius_km" /></div>
        </div>
        <p class="rt-staffing-note">Außerhalb des Grenzgebiets ist eine Zuweisung weiterhin möglich, aber niedriger priorisiert. Nur ausdrücklich ausgeschlossene Gebiete sperren die Zuweisung.</p>
        <div class="rt-staffing-heading"><h4>No-Go-Gebiete</h4><button type="button" class="rt-staffing-text-button" wire:click="addNoGoArea"><i class="far fa-plus" aria-hidden="true"></i> Gebiet hinzufügen</button></div>
        @foreach(($regionalPreference['no_go_areas'] ?? []) as $index => $area)
            <div class="rt-staffing-no-go" wire:key="staffing-no-go-{{ $regionalEmployeeId }}-{{ $index }}">
                <div><x-ui.forms.label :for="'staffing-no-go-location-'.$index" value="Ort" /><x-ui.forms.input :id="'staffing-no-go-location-'.$index" wire:model="regionalPreference.no_go_areas.{{ $index }}.location" maxlength="180" /></div>
                <div><x-ui.forms.label :for="'staffing-no-go-radius-'.$index" value="Radius (km)" /><x-ui.forms.input :id="'staffing-no-go-radius-'.$index" type="number" min="1" max="1000" wire:model="regionalPreference.no_go_areas.{{ $index }}.radius_km" /></div>
                <button type="button" class="rt-ops-panel__icon-button" wire:click="removeNoGoArea({{ $index }})" aria-label="No-Go-Gebiet {{ $index + 1 }} entfernen"><i class="far fa-trash-alt" aria-hidden="true"></i></button>
            </div>
        @endforeach
    @endif
    @error('regionalPreference')<p class="rt-staffing-alert" role="alert">{{ $message }}</p>@enderror
    <div class="rt-staffing-actions"><x-ui.buttons.button-basic type="button" wire:click="closeRegionalPreference">Abbrechen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" mode="primary" wire:click="saveRegionalPreference" wire:loading.attr="disabled">Einsatzgebiete speichern</x-ui.buttons.button-basic></div>
</section>
