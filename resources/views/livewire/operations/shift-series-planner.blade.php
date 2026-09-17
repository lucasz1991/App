<div>
<x-ui.buttons.button-basic wire:click="$set('open', true)"><i class="far fa-repeat" aria-hidden="true"></i>Vorlagen & Serien</x-ui.buttons.button-basic>
<x-operations.modal wire:model="open" title="Vorlagen & Serien" max-width="4xl">
    <x-operations.feedback />
    <div class="ops-actions"><x-ui.buttons.button-basic wire:click="editTemplate">Neue Vorlage</x-ui.buttons.button-basic><x-ui.buttons.button-basic mode="primary" wire:click="newSeries">Serie planen</x-ui.buttons.button-basic></div>
    <x-tables.table :columns="[['label'=>'Vorlage','key'=>'name'],['label'=>'Revision','key'=>'revision']]" :items="$templates" detail-action="editTemplate" row-view="components.tables.rows.operations.record" empty="Noch keine Vorlagen." />
    <h3 class="text-sm font-semibold">Angelegte Serien</h3>
    <x-tables.table :columns="[['label'=>'Serie','key'=>'id'],['label'=>'Auftrag','key'=>'order.order_number'],['label'=>'Dienste','key'=>'occurrences_count']]" :items="$history" row-view="components.tables.rows.operations.record" empty="Noch keine Serien." />
</x-operations.modal>
<x-operations.modal wire:model="templateOpen" title="Schichtvorlage" max-width="3xl">
<x-operations.feedback />
<form wire:submit="saveTemplate" class="ops-form">
    <x-operations.field label="Vorlagenname" model="template.name" required /><x-operations.field label="Schichttitel" model="template.title" required />
    <x-operations.field label="Funktion" model="template.role_name" required /><x-operations.field label="Einsatzort" model="template.location_name" />
    <x-operations.field label="Beginn" model="template.start_time" type="time" required /><x-operations.field label="Ende" model="template.end_time" type="time" required />
    <x-ui.forms.checkbox wire:model="template.end_next_day" label="Ende am Folgetag" />
    <x-operations.field label="Zeitzone" model="template.timezone" required />
    <x-operations.field label="Personalbedarf" model="template.required_staff" type="number" min="1" max="999" required />
    <x-operations.field label="Pause (min)" model="template.planned_break_minutes" type="number" min="0" max="1439" required />
    <fieldset class="ops-full space-y-2"><legend>Erforderliche Nachweise</legend>@foreach($types as $type)<x-ui.forms.checkbox wire:model="template.qualification_ids" value="{{ $type->id }}" :label="$type->name" />@endforeach</fieldset>
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Vorlage speichern</x-ui.buttons.button-basic></div>
</form>
</x-operations.modal>
<x-operations.modal wire:model="seriesOpen" title="Serie planen" max-width="4xl">
<x-operations.feedback />
<div class="ops-form">
    <x-operations.field label="Vorlage" model="series.template_id" type="select" required><option value="">Auswählen</option>@foreach($templates as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</x-operations.field>
    <x-operations.field label="Auftrag" model="series.order_id" type="select" required><option value="">Auswählen</option>@foreach($orders as $item)<option value="{{ $item->id }}">{{ $item->order_number }} · {{ $item->title }}</option>@endforeach</x-operations.field>
    <x-operations.field label="Von" model="series.from" type="date" required /><x-operations.field label="Bis" model="series.until" type="date" required />
    <fieldset class="ops-full ops-actions"><legend>Wochentage</legend>@foreach([1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'] as $day=>$label)<x-ui.forms.checkbox wire:model="series.weekdays" :value="$day" :label="$label" />@endforeach</fieldset>
    <x-operations.field label="Ausnahmen (JJJJ-MM-TT, mit Komma trennen)" model="exceptions" :wide="true" />
    <div class="ops-full"><x-ui.buttons.button-basic wire:click="preview" wire:loading.attr="disabled">Vorschau prüfen</x-ui.buttons.button-basic></div>
</div>
@if($previewRows)
    <h3 class="text-sm font-semibold">{{ count($previewRows) }} Dienste · Entwurf</h3>
    <x-tables.table :columns="[['label'=>'Datum','key'=>'date'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Ende','key'=>'ends_at'],['label'=>'Prüfung','key'=>'error']]" :items="collect($previewRows)->map(fn ($row)=>(object)$row)" row-view="components.tables.rows.operations.series-preview" />
    @if(!collect($previewRows)->contains(fn ($row)=>$row['error'] !== null))<x-ui.buttons.button-basic mode="primary" wire:click="generate" wire:loading.attr="disabled">Entwurfsschichten anlegen</x-ui.buttons.button-basic>@endif
@endif
</x-operations.modal>
</div>
