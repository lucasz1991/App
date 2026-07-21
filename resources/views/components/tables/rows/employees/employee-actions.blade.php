<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger />
    </x-slot>

    <x-slot name="content">
        <x-dropdown-link href="{{ route('admin.user-profile', $item->id) }}" :can="'users.profiles.view'">
            <i class="far fa-id-card mr-2"></i>
            {{ __('app.view_profile') }}
        </x-dropdown-link>

        <x-dropdown-link wire:click.prevent="openMessage({{ $item->id }})" :can="'users.messages.create'">
            <i class="far fa-paper-plane mr-2"></i>
            {{ __('app.compose_message') }}
        </x-dropdown-link>

        {{-- Details: normale Navigation --}}
        <x-dropdown-link wire:click.prevent="openEdit({{ $item->id }})" :can="'employees.create'">
            <i class="far fa-pen mr-2"></i>
            {{ __('app.edit') }}
        </x-dropdown-link>

        @if ($item->status)
            <x-dropdown-link wire:click.prevent="deactivateUser({{ $item->id }})" :can="'employees.create'" class="hover:bg-amber-50 dark:hover:bg-amber-500/10">
                <i class="far fa-pause-circle mr-2"></i>
                {{ __('app.deactivate') }}
            </x-dropdown-link>
        @else
            <x-dropdown-link wire:click.prevent="activateUser({{ $item->id }})" :can="'employees.create'" class="hover:bg-emerald-50 dark:hover:bg-emerald-500/10">
                <i class="far fa-play-circle mr-2"></i>
                {{ __('app.activate') }}
            </x-dropdown-link>
        @endif

    </x-slot>
</x-dropdown>
