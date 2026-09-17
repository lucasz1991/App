<section class="space-y-3" aria-label="Auftragsbedarf">
<header class="ops-toolbar"><h3 class="text-sm font-semibold">Auftragsbedarf</h3><x-ui.buttons.button-basic wire:click="edit">Bedarf hinzufügen</x-ui.buttons.button-basic></header>
<x-operations.feedback />
<x-tables.table :columns="[['label'=>'Tätigkeit','key'=>'role'],['label'=>'Zeitfenster','key'=>'interval'],['label'=>'Bedarf / Planung','key'=>'coverage'],['label'=>'Aktionen','key'=>'actions']]" :items="$demands" row-view="components.tables.rows.operations.order-demand" empty="Noch kein eigenständiger Bedarf erfasst." />
@if($demands->where('status','active')->isNotEmpty())<x-operations.field label="Pause für neue Entwurfsschicht (min)" model="breakMinutes" type="number" min="0" max="1439" />@endif
<x-operations.modal wire:model="formOpen" title="Auftragsbedarf">
<x-operations.feedback /><form wire:submit="save" class="ops-form">
    <x-operations.field label="Tätigkeit / Funktion" model="form.role_name" required /><x-operations.field label="Personalbedarf" model="form.required_staff" type="number" min="1" max="999" required />
    <x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="form.ends_at" type="datetime-local" required />
    <x-operations.field label="Zeitzone" model="form.timezone" required />
    <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Bedarf speichern</x-ui.buttons.button-basic></div>
</form></x-operations.modal>
</section>
