<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <button type="button" class="text-center px-4 py-2 text-xl font-semibold bg-white dark:bg-slate-800 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg border border-gray-200 dark:border-slate-700">
            &#x22EE;
        </button>
    </x-slot>

    <x-slot name="content">
        <x-dropdown-link href="{{ route('admin.user-profile', $item->id) }}" :can="'users.profiles.view'">
            <i class="far fa-id-card mr-2"></i>
            {{ __('app.view_profile') }}
        </x-dropdown-link>

        {{-- Details: normale Navigation --}}
        <x-dropdown-link wire:click.prevent="openEdit({{ $item->id }})" :can="'employees.create'">
            <i class="far fa-pen mr-2"></i>
            {{ __('app.edit') }}
        </x-dropdown-link>

        @if ($item->status)
            <x-dropdown-link wire:click.prevent="deactivateUser({{ $item->id }})" :can="'employees.create'" class="hover:bg-yellow-100 dark:hover:bg-yellow-500/10">
                <i class="far fa-pause-circle mr-2"></i>
                {{ __('app.deactivate') }}
            </x-dropdown-link>
        @else
            <x-dropdown-link wire:click.prevent="activateUser({{ $item->id }})" :can="'employees.create'" class="hover:bg-green-100 dark:hover:bg-green-500/10">
                <i class="far fa-play-circle mr-2"></i>
                {{ __('app.activate') }}
            </x-dropdown-link>
        @endif

    </x-slot>
</x-dropdown>
