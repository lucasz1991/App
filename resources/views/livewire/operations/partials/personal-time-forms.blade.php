@php
    $manualFormId = 'employee-duty-time-'.$this->getId();
    $correctionFormId = 'employee-correction-'.$this->getId();
@endphp
@if($manualAssignments->isNotEmpty())
    <x-operations.modal wire:model="manualOpen" title="Dienstzeit nachtragen">
        <form id="{{ $manualFormId }}" wire:submit="saveManual" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
            <x-operations.field label="Dienst" model="manualAssignmentId" type="select" :wide="true" :live="true" required>
                <option value="">Dienst auswählen</option>
                @foreach($manualAssignments as $item)<option value="{{ $item->id }}">{{ $item->shift->starts_at->setTimezone($item->shift->timezone)->format('d.m.Y H:i') }} · {{ $item->shift->title }} · {{ $item->shift->timezone }}</option>@endforeach
            </x-operations.field>
            <x-operations.work-time-interval model="manualTime" :values="$manualTime" :timezone="$manualAssignments->firstWhere('id', $manualAssignmentId)?->shift?->timezone" />
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
        @if(isset($correctingEntry) && $correctingEntry->status === 'returned' && filled($correctingEntry->review_note))
            <div class="rt-employee-form__review-note ops-full"><strong>Rückgabe aus der Zeitprüfung</strong><p>{{ $correctingEntry->review_note }}</p></div>
        @endif
        <x-operations.work-time-interval model="correction" :values="$correction ?? []" :timezone="($correctingEntry ?? null)?->timezone" />
        <div class="ops-full"><x-operations.field label="Korrekturgrund" model="correction.note" type="textarea" rows="2" required minlength="5" maxlength="1000" /><p class="rt-employee-form__hint">Mindestens 5 Zeichen.</p></div>
    </form>
    <x-slot:footer><div class="rt-employee-form__actions">
        <x-ui.buttons.button-basic type="button" wire:click="cancelCorrection" wire:loading.attr="disabled" wire:target="correct">Abbrechen</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic type="submit" form="{{ $correctionFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="correct">Korrektur speichern</x-ui.buttons.button-basic>
    </div></x-slot:footer>
</x-operations.modal>
