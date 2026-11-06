@if($tab === 'records')
    @php
        $qualificationFormId = 'employee-qualification-'.$this->getId();
        $evidenceLimitKb = (int) config('operations.evidence_max_kilobytes');
        $evidenceLimitLabel = $evidenceLimitKb % 1024 === 0
            ? number_format($evidenceLimitKb / 1024, 0, ',', '.').' MB'
            : number_format($evidenceLimitKb, 0, ',', '.').' KB';
    @endphp
    <x-operations.modal wire:model="qualificationOpen" title="Nachweis einreichen">
        <form id="{{ $qualificationFormId }}" wire:submit="upload" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
            <p class="rt-employee-form__hint ops-full">Qualifikation und Gültigkeit</p>
            <x-operations.field label="Nachweisart" model="qualification.qualification_type_id" type="select" required :wide="true"><option value="">Nachweisart auswählen</option>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Gültig ab" model="qualification.valid_from" type="date" required />
            <x-operations.field label="Gültig bis" model="qualification.valid_until" type="date" required />
            <div class="rt-employee-form__upload ops-full">
                @if($evidence)
                    <div class="rt-employee-form__file"><i class="far fa-file-lines" aria-hidden="true"></i><div><strong>{{ $evidence->getClientOriginalName() }}</strong><span>{{ number_format($evidence->getSize() / 1024 / 1024, 1, ',', '.') }} MB · Ausgewählt</span></div><x-ui.buttons.button-basic type="button" mode="link" wire:click="$set('evidence', null)" wire:loading.attr="disabled" wire:target="evidence,upload" aria-label="Ausgewählte Datei entfernen"><i class="far fa-xmark" aria-hidden="true"></i></x-ui.buttons.button-basic></div>
                @endif
                <x-operations.field :label="$evidence ? 'Datei ersetzen' : 'Datei auswählen'" model="evidence" type="file" accept="application/pdf,image/jpeg,image/png" :required="!$evidence" />
                <p class="rt-employee-form__hint">PDF, JPG oder PNG · maximal {{ $evidenceLimitLabel }}</p>
                <p class="rt-employee-form__hint" wire:loading wire:target="evidence" role="status">Datei wird hochgeladen …</p>
            </div>
            <p class="rt-employee-form__hint ops-full">Dein Nachweis wird nach dem Einreichen geprüft.</p>
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" wire:click="$set('qualificationOpen', false)" wire:loading.attr="disabled" wire:target="upload,evidence">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="{{ $qualificationFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="upload,evidence">Nachweis einreichen</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
@elseif($tab === 'absences')
    @php($absenceFormId = 'employee-absence-'.$this->getId())
    <x-operations.modal wire:model="absenceOpen" :title="($absence['kind'] ?? 'vacation') === 'vacation' ? 'Urlaub beantragen' : 'Abwesenheit beantragen'">
        <form id="{{ $absenceFormId }}" wire:submit="requestAbsence" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
            <div class="ops-full"><x-ui.forms.label :for="$absenceFormId.'-kind'" value="Art der Abwesenheit" /><x-ui.forms.select :id="$absenceFormId.'-kind'" wire:model.live="absence.kind" aria-label="Art der Abwesenheit"><option value="vacation">Urlaub</option><option value="unavailable">Nicht verfügbar</option><option value="other">Abwesenheit</option></x-ui.forms.select><x-ui.forms.input-error for="absence.kind" /></div>
            @if($absenceWholeDays)
                <div class="ops-full">
                    <x-ui.forms.date-range-picker :inline="true" from-model="absenceFirstDay" until-model="absenceLastDay" :from="$absenceFirstDay" :until="$absenceLastDay" from-label="Erster Tag" until-label="Letzter Tag" :preset-keys="[]" />
                    <x-ui.forms.input-error for="absenceFirstDay" /><x-ui.forms.input-error for="absenceLastDay" />
                </div>
            @else
                <x-operations.field label="Beginn · Datum und Uhrzeit" model="absence.starts_at" type="datetime-local" required />
                <x-operations.field label="Ende · Datum und Uhrzeit" model="absence.ends_at" type="datetime-local" required />
            @endif
            <div class="ops-full"><x-ui.forms.checkbox :id="$absenceFormId.'-whole-days'" wire:model.live="absenceWholeDays" label="Ganze Tage" help="Ohne Auswahl kannst du genaue Uhrzeiten angeben." /><p class="rt-employee-form__hint">Bei ganzen Tagen gehört der letzte gewählte Tag vollständig zum Antrag.</p></div>
            <x-operations.field label="Notiz (optional)" model="absence.note" type="textarea" rows="2" maxlength="1000" :wide="true" />
            <details class="rt-employee-form__more ops-full" @if($errors->has('absence.timezone')) open @endif>
                <summary>Weitere Angaben <span>· {{ $absence['timezone'] ?: $displayTimezone }}</span></summary>
                <div class="rt-employee-form__grid"><x-operations.field label="Zeitzone" model="absence.timezone" required /></div>
            </details>
            <p class="rt-employee-form__hint ops-full">Dein Antrag wird nach dem Einreichen geprüft. Den Status findest du unter Urlaub &amp; Abwesenheit. Die markierten Kalendertage sind keine Berechnung des Urlaubsabzugs.</p>
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" wire:click="$set('absenceOpen', false)" wire:loading.attr="disabled" wire:target="requestAbsence">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="{{ $absenceFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="requestAbsence">Antrag einreichen</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
@endif
