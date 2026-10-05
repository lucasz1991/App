<div>
    @if($showTrigger)<x-ui.buttons.button-basic type="button" wire:click="$set('open',true)"><i class="far fa-copy" aria-hidden="true"></i>Planvarianten</x-ui.buttons.button-basic>@endif
    <x-operations.modal wire:model="open" title="Planvarianten" max-width="4xl">
        <div class="ops-form"><x-operations.field label="Variantenname" model="name" required /><x-operations.field label="Kopie um Tage verschieben (0 = Entwurfsvergleich)" model="copyDays" type="number" min="0" max="365" /></div>
        <x-tables.table :columns="[['label'=>'Dienst','key'=>'title'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Status','key'=>'status']]" :items="$shifts" :selected-items="$selectedShiftIds" selection-action="toggleSelection" row-view="components.tables.rows.operations.record" empty="Keine zukünftigen Dienste." />
        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="capture" wire:loading.attr="disabled">Variante aus Auswahl erstellen</x-ui.buttons.button-basic>
        <x-tables.table :columns="[['label'=>'Variante','key'=>'name'],['label'=>'Revision','key'=>'revision'],['label'=>'Status','key'=>'status']]" :items="$variants" detail-action="edit" row-view="components.tables.rows.operations.record" empty="Keine Varianten." />
    </x-operations.modal>
    <x-operations.modal wire:model="editOpen" title="Planvariante prüfen" max-width="4xl">
        @if($form)
            <div class="ops-toolbar"><span class="text-sm font-semibold">{{ $form['name'] }} · Revision {{ $revision }}</span><span class="ops-badge">{{ ['draft'=>'Entwurf','approved'=>'Freigegeben','applied'=>'Übernommen'][$status] }}</span></div>
            @if($status === 'draft')<form wire:submit="save" class="ops-form"><x-operations.field label="Name" model="form.name" required /><x-operations.field label="Kommentar" model="form.comment" /><x-operations.field label="Von" model="form.from" type="date" /><x-operations.field label="Bis" model="form.until" type="date" /><x-operations.field label="Zeitzone" model="form.timezone" required /><div class="ops-full"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Entwurf speichern</x-ui.buttons.button-basic></div></form>@endif
            <x-tables.table :columns="[['label'=>'Dienst','key'=>'title'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Ende','key'=>'ends_at'],['label'=>'Besetzung','key'=>'people_count']]" :items="$entries" :detail-action="$status === 'draft' ? 'editEntry' : null" row-view="components.tables.rows.operations.record" />
            @if($status !== 'applied')<x-ui.buttons.button-basic type="button" wire:click="preview" wire:loading.attr="disabled">Alle Dienste gemeinsam prüfen</x-ui.buttons.button-basic>@endif
            @if($previewRows)<x-tables.table :columns="[['label'=>'Dienst','key'=>'title'],['label'=>'Offene Plätze','key'=>'open'],['label'=>'Prüfung','key'=>'error']]" :items="$preview" row-view="components.tables.rows.operations.record" />@endif
            <div class="ops-actions">@if($status === 'draft' && $valid)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="approve" wire:loading.attr="disabled">Variante freigeben</x-ui.buttons.button-basic>@elseif($status === 'approved')<x-ui.buttons.button-basic type="button" mode="primary" wire:click="apply" wire:confirm="Variante als Entwurf übernehmen? Es wird nichts veröffentlicht." wire:loading.attr="disabled">Als Entwurf übernehmen</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic type="button" wire:click="$set('editOpen',false)">Schließen</x-ui.buttons.button-basic></div>
        @endif
    </x-operations.modal>
    <x-operations.modal wire:model="entryOpen" title="Varianten-Dienst" max-width="4xl">
        @if($entry)<form wire:submit="keepEntry" class="ops-form">
            <x-operations.field label="Auftrag" model="entry.order_id" type="select">@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Diensttitel" model="entry.title" required /><x-operations.field label="Tätigkeit" model="entry.role_name" required /><x-operations.field label="Einsatzort" model="entry.location_name" />
            <x-operations.field label="Beginn" model="entry.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="entry.ends_at" type="datetime-local" required /><x-operations.field label="Zeitzone" model="entry.timezone" required />
            <x-operations.field label="Personalbedarf" model="entry.required_staff" type="number" min="1" max="999" /><x-operations.field label="Pause (min)" model="entry.planned_break_minutes" type="number" min="0" max="1439" /><x-operations.field label="Anreise-/Ablösepuffer (min)" model="entry.transfer_buffer_minutes" type="number" min="0" max="10080" />
            <fieldset class="ops-full grid gap-1 sm:grid-cols-2"><legend class="text-sm font-semibold">Mitarbeiter</legend>@foreach($people as $person)<x-ui.forms.checkbox wire:model="entry.user_ids" :value="$person->id" :label="$person->name" />@endforeach</fieldset>
            <fieldset class="ops-full grid gap-1 sm:grid-cols-2"><legend class="text-sm font-semibold">Erforderliche Nachweise</legend>@foreach($types as $type)<x-ui.forms.checkbox wire:model="entry.qualification_ids" :value="$type->id" :label="$type->name" />@endforeach</fieldset>
            <div class="ops-full ops-actions"><x-ui.buttons.button-basic mode="primary" type="submit">In Variante übernehmen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('entryOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
        </form>@endif
    </x-operations.modal>
</div>
