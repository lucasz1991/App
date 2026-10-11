@can('employees.create')
    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="openCreate"
        wire:loading.attr="disabled" wire:target="openCreate" class="employee-directory__create"
        :title="__('app.new_employee_hint')" :aria-label="__('app.new_employee')">
        <i class="far fa-plus" aria-hidden="true"></i>
    </x-ui.buttons.button-basic>
@endcan
<x-ui.dropdown.page-actions>
    <x-dropdown-link wire:click.prevent="openInvite" :can="'employees.create'" :title="__('app.invite_employee_hint')">
        <i class="far fa-paper-plane mr-2" aria-hidden="true"></i>{{ __('app.invite_employee') }}
    </x-dropdown-link>
    <x-dropdown-link wire:click.prevent="openTeamRbacModal" :can="'roles.manage'" :title="__('app.teams_permissions_hint')">
        <i class="far fa-shield-alt mr-2" aria-hidden="true"></i>{{ __('app.teams_permissions') }}
    </x-dropdown-link>
</x-ui.dropdown.page-actions>
@include('livewire.admin.employees.directory-filter-trigger')
