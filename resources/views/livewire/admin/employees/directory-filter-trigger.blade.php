<x-ui.dropdown.anchor-dropdown width="80" dropdown-id="employee-directory-filters"
    content-role="dialog" content-label="Mitarbeiter filtern" layer-group="topbar-controls"
    content-classes="employee-directory__filter-panel p-4 bg-rt-surface dark:bg-rt-dark-surface"
>
    <x-slot:trigger>
        <x-ui.buttons.button-basic mode="secondary" type="button" class="employee-directory__filter-trigger" aria-label="Mitarbeiter filtern" title="Mitarbeiter filtern">
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            @if($role !== '' || $accountStatus !== '' || $teamId)
                <span class="employee-directory__filter-count">{{ (int)($role !== '') + (int)($accountStatus !== '') + (int)(bool)$teamId }}</span>
            @endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="text-sm font-semibold">Mitarbeiter filtern</h2>
            <x-ui.buttons.button-basic mode="link" type="button" wire:click="resetFilters">Zurücksetzen</x-ui.buttons.button-basic>
        </div>
        @include('livewire.admin.employees.directory-filters')
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
