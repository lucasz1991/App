<section class="space-y-4" aria-label="Dienstablauf">
<x-operations.feedback />
<header class="ops-toolbar"><h3 class="text-sm font-semibold">Dienstabschnitte</h3>@if(!$employeeMode)<x-ui.buttons.button-basic wire:click="editSection">Abschnitt hinzufügen</x-ui.buttons.button-basic>@endif</header>
<x-tables.table :columns="[['label'=>'Abschnitt','key'=>'section'],['label'=>'Zeit','key'=>'time'],['label'=>'Aktion','key'=>'action']]" :items="$sections" row-view="components.tables.rows.operations.duty-section" empty="Keine Dienstabschnitte hinterlegt." />
<header class="ops-toolbar"><h3 class="text-sm font-semibold">{{ $employeeMode ? 'Meine Meldungen' : 'Operative Meldungen' }}</h3><x-ui.buttons.button-basic wire:click="newReport">Meldung erfassen</x-ui.buttons.button-basic></header>
<x-tables.table :columns="[['label'=>'Meldung','key'=>'message'],['label'=>'Status','key'=>'status']]" :items="$reports" row-view="components.tables.rows.operations.duty-report" empty="Keine Meldungen." />
<x-operations.modal wire:model="sectionOpen" title="Dienstabschnitt"><form wire:submit="saveSection" class="ops-form">
    <x-operations.field label="Abschnitt" model="section.kind" type="select">@foreach($sectionKinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
    <x-operations.field label="Bezeichnung (optional)" model="section.label" maxlength="180" />
    <x-operations.field label="Beginn · Dienstzeitzone" model="section.starts_at" type="datetime-local" required /><x-operations.field label="Ende · Dienstzeitzone" model="section.ends_at" type="datetime-local" required />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Abschnitt speichern</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
<x-operations.modal wire:model="reportOpen" title="Operative Meldung"><form wire:submit="sendReport" class="ops-form">
    <x-operations.field label="Meldungsart" model="report.kind" type="select">@foreach($reportKinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
    <x-operations.field label="Verspätung (min, nur bei Verspätung)" model="report.delay_minutes" type="number" min="1" max="1440" />
    <x-operations.field label="Meldung" model="report.message" type="textarea" maxlength="2000" required :wide="true" />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Meldung speichern</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
<x-operations.modal wire:model="resolveOpen" title="Meldung bearbeiten"><form wire:submit="resolve" class="ops-form">
    <x-operations.field label="Maßnahme / Ergebnis" model="resolution" type="textarea" required maxlength="2000" :wide="true" />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Als erledigt markieren</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
</section>
