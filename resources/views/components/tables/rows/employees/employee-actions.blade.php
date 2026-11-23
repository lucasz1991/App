<x-ui.dropdown.anchor-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger class="rt-employee-action-trigger" orientation="vertical" />
    </x-slot>

    <x-slot name="content">
        @if (auth()->user()->canViewManagementDashboard())
            <x-ui.dropdown.dropdown-link
                href="{{ route(auth()->user()->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $item->id) }}"
                :can="'users.profiles.view'"
            >
                <i class="far fa-id-card mr-2"></i>
                {{ __('app.view_profile') }}
            </x-ui.dropdown.dropdown-link>
        @endif

        <x-ui.dropdown.dropdown-link wire:click.prevent="openMessage({{ $item->id }})" :can="'users.messages.create'">
            <i class="far fa-paper-plane mr-2"></i>
            {{ __('app.compose_message') }}
        </x-ui.dropdown.dropdown-link>

        {{-- Details: normale Navigation --}}
        <x-ui.dropdown.dropdown-link wire:click.prevent="openEdit({{ $item->id }})" :can="'employees.create'">
            <i class="far fa-pen mr-2"></i>
            {{ __('app.edit') }}
        </x-ui.dropdown.dropdown-link>

        @if ($item->status)
            <x-ui.dropdown.dropdown-link wire:click.prevent="deactivateUser({{ $item->id }})" :can="'employees.create'" tone="warning">
                <i class="far fa-pause-circle mr-2"></i>
                {{ __('app.deactivate') }}
            </x-ui.dropdown.dropdown-link>
        @else
            <x-ui.dropdown.dropdown-link wire:click.prevent="activateUser({{ $item->id }})" :can="'employees.create'" tone="success">
                <i class="far fa-play-circle mr-2"></i>
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
                    <i class="far fa-trash-alt mr-2"></i>
                    {{ __('app.delete_user') }}
                </x-ui.dropdown.dropdown-link>
            @endif
        @endcan

    </x-slot>
</x-ui.dropdown.anchor-dropdown>
