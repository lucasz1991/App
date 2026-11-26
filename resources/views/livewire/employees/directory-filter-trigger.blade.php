<x-tables.filter-dropdown
    dropdown-id="employee-directory-filters"
    label="Mitarbeiter filtern"
    layer-group="topbar-controls"
    :count="(int) ($role !== '') + (int) ($accountStatus !== '') + (int) (bool) $teamId"
    reset-action="resetFilters"
    reset-label="Zurücksetzen"
    panel-class="employee-directory__filter-panel"
    class="employee-directory__filter-trigger"
>
    @include('livewire.admin.employees.directory-filters')
</x-tables.filter-dropdown>
