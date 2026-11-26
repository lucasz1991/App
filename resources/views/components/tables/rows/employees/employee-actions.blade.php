<x-tables.row-actions class="rt-employee-action-trigger" :label="__('app.actions').': '.$item->name">
    @if (auth()->user()->canViewManagementDashboard())
        <x-ui.dropdown.dropdown-link
            href="{{ route('employees.show', $item->id) }}"
            :can="'users.profiles.view'"
        >
            <i class="far fa-id-card" aria-hidden="true"></i>
            {{ __('app.view_profile') }}
        </x-ui.dropdown.dropdown-link>
    @endif

    <x-ui.dropdown.dropdown-link wire:click.prevent="openMessage({{ $item->id }})" :can="'users.messages.create'">
        <i class="far fa-paper-plane" aria-hidden="true"></i>
        {{ __('app.compose_message') }}
    </x-ui.dropdown.dropdown-link>

    <x-ui.dropdown.dropdown-link wire:click.prevent="openEdit({{ $item->id }})" :can="'employees.create'">
        <i class="far fa-pen" aria-hidden="true"></i>
        {{ __('app.edit') }}
    </x-ui.dropdown.dropdown-link>

    @if ($item->status)
        <x-ui.dropdown.dropdown-link wire:click.prevent="deactivateUser({{ $item->id }})" :can="'employees.create'" tone="warning">
            <i class="far fa-pause-circle" aria-hidden="true"></i>
            {{ __('app.deactivate') }}
        </x-ui.dropdown.dropdown-link>
    @else
        <x-ui.dropdown.dropdown-link wire:click.prevent="activateUser({{ $item->id }})" :can="'employees.create'" tone="success">
            <i class="far fa-play-circle" aria-hidden="true"></i>
            {{ __('app.activate') }}
        </x-ui.dropdown.dropdown-link>
    @endif

    @can('employees.delete')
        @if ((int) $item->id !== (int) auth()->id() && ! $item->isSuperAdmin())
            <x-ui.dropdown.dropdown-link
                confirm-method="deleteUser"
                :confirm-arguments="[(int) $item->id]"
                :confirm-title="__('app.delete_user')"
                :confirm-message="__('app.delete_user_confirm')"
                :confirm-label="__('app.delete')"
                tone="danger"
            >
                <i class="far fa-trash-alt" aria-hidden="true"></i>
                {{ __('app.delete_user') }}
            </x-ui.dropdown.dropdown-link>
        @endif
    @endcan
</x-tables.row-actions>
