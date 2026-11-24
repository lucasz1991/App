<section class="space-y-4" aria-label="Dienstablauf">
<x-operations.feedback />
@if($canUseCatalog)<x-ui.buttons.button-basic type="button" wire:click="useCatalog({{ $currentRevision }})" wire:confirm="Die freigegebenen Anforderungen für diesen Dienstentwurf übernehmen?">Aktuelle Tätigkeitsanforderungen übernehmen</x-ui.buttons.button-basic>@endif
<header class="ops-toolbar"><h3 class="text-sm font-semibold">Dienstabschnitte</h3>@if(!$employeeMode)<x-ui.buttons.button-basic wire:click="editSection">Abschnitt hinzufügen</x-ui.buttons.button-basic>@endif</header>
<x-tables.table :columns="[['label'=>'Abschnitt','key'=>'section'],['label'=>'Zeit','key'=>'time'],['label'=>'Aktion','key'=>'action']]" :items="$sections" row-view="components.tables.rows.time.duty-section-row" empty="Keine Dienstabschnitte hinterlegt." />
<header class="ops-toolbar"><h3 class="text-sm font-semibold">{{ $employeeMode ? 'Meine Meldungen' : 'Operative Meldungen' }}</h3><x-ui.buttons.button-basic wire:click="newReport">Meldung erfassen</x-ui.buttons.button-basic></header>
<x-tables.table :columns="[['label'=>'Meldung','key'=>'message'],['label'=>'Status','key'=>'status']]" :items="$reports" row-view="components.tables.rows.time.duty-report-row" empty="Keine Meldungen." />
<x-operations.modal wire:model="sectionOpen" title="Dienstabschnitt"><form wire:submit="saveSection" class="ops-form">
    <x-operations.field label="Abschnitt" model="section.kind" type="select">@foreach($sectionKinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
    @if($activities->isNotEmpty())<x-operations.field label="Tätigkeit mit eigenem Beleg" model="section.activity_id" type="select"><option value="">Kein gesonderter Leistungsnachweis</option>@foreach($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }} · {{ $activity->code }}</option>@endforeach</x-operations.field>@endif
    <x-operations.field label="Bezeichnung (optional)" model="section.label" maxlength="180" />
    <x-operations.field label="Beginn · Dienstzeitzone" model="section.starts_at" type="datetime-local" required /><x-operations.field label="Ende · Dienstzeitzone" model="section.ends_at" type="datetime-local" required />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Abschnitt speichern</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
@php($reportFormId = 'duty-report-'.$this->getId())
<x-operations.modal wire:model="reportOpen" title="Operative Meldung">
    <form id="{{ $reportFormId }}" wire:submit="sendReport" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
        <x-operations.field label="Meldungsart" model="report.kind" type="select" :live="true">
            @foreach($reportKinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </x-operations.field>
        @if(($report['kind'] ?? '') === 'delay')
            <x-operations.field label="Verspätung in Minuten" model="report.delay_minutes" type="number" min="1" max="1440" required />
        @endif
        <x-operations.field label="Meldung" model="report.message" type="textarea" minlength="3" maxlength="2000" required :wide="true" />
    </form>
    <x-slot:footer><div class="rt-employee-form__actions">
        <x-ui.buttons.button-basic type="button" wire:click="$set('reportOpen', false)" wire:loading.attr="disabled" wire:target="sendReport">Abbrechen</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic mode="primary" type="submit" form="{{ $reportFormId }}" wire:loading.attr="disabled" wire:target="sendReport">Meldung speichern</x-ui.buttons.button-basic>
    </div></x-slot:footer>
</x-operations.modal>
<x-operations.modal wire:model="resolveOpen" title="Meldung bearbeiten"><form wire:submit="resolve" class="ops-form">
    <x-operations.field label="Maßnahme / Ergebnis" model="resolution" type="textarea" required maxlength="2000" :wide="true" />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Als erledigt markieren</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
</section>
