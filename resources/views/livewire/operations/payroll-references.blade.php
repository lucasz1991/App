<div class="rt-personnel-management">
    <x-ui.buttons.button-basic wire:click="$set('open', true)">Lohnzuordnung</x-ui.buttons.button-basic>
    <x-operations.modal wire:model="open" title="Lohnzuordnung">
        <div class="rt-personnel-management ops-stack">
            <x-tables.toolbar title="Lohnzuordnungen durchsuchen" id="payroll-reference-filters">
                <x-slot:search><x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" /></x-slot:search>
            </x-tables.toolbar>
            <x-tables.table class="rt-personnel-table" label="Lohnzuordnungen" :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Arbeitgeberreferenz','key'=>'employer_reference'],['label'=>'Personalnummer','key'=>'personnel_number']]" :items="$users" detail-action="edit" :detail-on-click="true" row-view="components.tables.rows.shared.record-row" />
            <x-tables.pagination :paginator="$users" />
        </div>
    </x-operations.modal>
    <x-operations.modal wire:model.live="editOpen" title="Lohnzuordnung bearbeiten">
        <form id="payroll-reference-{{ $this->getId() }}" wire:submit="save" class="rt-personnel-management ops-form rt-personal-form rt-employee-form">
            <header class="ops-full rt-personnel-section-heading"><div><p>Mitarbeiter</p><h3>{{ $employeeName }}</h3></div></header>
            <x-operations.field label="Arbeitgeberreferenz" model="form.employer_reference" maxlength="64" required />
            <x-operations.field label="Personalnummer" model="form.personnel_number" maxlength="64" required />
            <x-operations.field label="Externe Mitarbeiterreferenz (optional)" model="form.external_employee_reference" maxlength="100" :wide="true" />
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" wire:click="returnToList">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="payroll-reference-{{ $this->getId() }}" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
</div>
