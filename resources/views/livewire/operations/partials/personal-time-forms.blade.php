@php
    $manualFormId = 'employee-duty-time-'.$this->getId();
    $correctionFormId = 'employee-correction-'.$this->getId();
@endphp
@if($manualAssignments->isNotEmpty())
    <x-operations.modal wire:model="manualOpen" title="Dienstzeit nachtragen">
        <form id="{{ $manualFormId }}" wire:submit="saveManual" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
            <x-operations.field label="Dienst" model="manualAssignmentId" type="select" :wide="true" required>
                <option value="">Dienst auswählen</option>
                @foreach($manualAssignments as $item)<option value="{{ $item->id }}">{{ $item->shift->starts_at->setTimezone($item->shift->timezone)->format('d.m.Y H:i') }} · {{ $item->shift->title }} · {{ $item->shift->timezone }}</option>@endforeach
            </x-operations.field>
            <fieldset class="rt-employee-form__section ops-full"><legend>Zeitraum und Pause</legend>
                <div class="rt-employee-form__grid">
                    <x-operations.field label="Beginn · Dienstzeitzone" model="manualTime.starts_at" type="datetime-local" required />
                    <x-operations.field label="Ende · Dienstzeitzone" model="manualTime.ends_at" type="datetime-local" required />
                    <x-operations.field label="Pause in Minuten" model="manualTime.pause_minutes" type="number" min="0" max="1440" required />
                    <p class="rt-employee-form__hint">Für Nachtdienste das Enddatum auf den Folgetag setzen. Die Zeitzone steht beim ausgewählten Dienst.</p>
                </div>
            </fieldset>
            <div class="ops-full"><x-operations.field label="Grund des Nachtrags" model="manualTime.note" type="textarea" rows="2" required minlength="5" maxlength="1000" /><p class="rt-employee-form__hint">Mindestens 5 Zeichen.</p></div>
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" wire:click="$set('manualOpen', false)" wire:loading.attr="disabled" wire:target="saveManual">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="{{ $manualFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="saveManual">Nachtrag speichern</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
@endif
<x-operations.modal wire:model="correctionOpen" title="Zeit korrigieren">
    <form id="{{ $correctionFormId }}" wire:submit="correct" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
        <x-operations.field label="Beginn · Datum und Uhrzeit" model="correction.starts_at" type="datetime-local" required />
        <x-operations.field label="Ende · Datum und Uhrzeit" model="correction.ends_at" type="datetime-local" required />
        <x-operations.field label="Pause in Minuten" model="correction.pause_minutes" type="number" min="0" max="1440" required />
        <p class="rt-employee-form__hint">Es gilt die Zeitzone der bestehenden Zeitmeldung. Für Nachtdienste auch das Enddatum prüfen.</p>
        <div class="ops-full"><x-operations.field label="Korrekturgrund" model="correction.note" type="textarea" rows="2" required minlength="5" maxlength="1000" /><p class="rt-employee-form__hint">Mindestens 5 Zeichen.</p></div>
    </form>
    <x-slot:footer><div class="rt-employee-form__actions">
        <x-ui.buttons.button-basic type="button" wire:click="cancelCorrection" wire:loading.attr="disabled" wire:target="correct">Abbrechen</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic type="submit" form="{{ $correctionFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="correct">Korrektur speichern</x-ui.buttons.button-basic>
    </div></x-slot:footer>
</x-operations.modal>
