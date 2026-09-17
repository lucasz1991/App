<div>
    <x-ui.buttons.button-basic wire:click="$set('open', true)">Lohnzuordnung</x-ui.buttons.button-basic>
    <x-operations.modal wire:model="open" title="Lohnzuordnung">
        <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />
        <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Arbeitgeberreferenz','key'=>'employer_reference'],['label'=>'Personalnummer','key'=>'personnel_number']]" :items="$users" detail-action="edit" row-view="components.tables.rows.operations.record" />
        {{ $users->links() }}
    </x-operations.modal>
    <x-operations.modal wire:model="editOpen" title="Lohnzuordnung bearbeiten">
        <form wire:submit="save" class="ops-form">
            <x-operations.field label="Arbeitgeberreferenz" model="form.employer_reference" maxlength="64" required />
            <x-operations.field label="Personalnummer" model="form.personnel_number" maxlength="64" required />
            <x-operations.field label="Externe Mitarbeiterreferenz (optional)" model="form.external_employee_reference" maxlength="100" :wide="true" />
            <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic></div>
        </form>
    </x-operations.modal>
</div>
