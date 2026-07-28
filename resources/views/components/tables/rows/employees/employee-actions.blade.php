@php
    /*
     * Blade-Direktiven innerhalb eines Attributwerts eines anonymen
     * Components werden nicht erneut kompiliert. Deshalb muss der komplette
     * Alpine-Ausdruck einschliesslich der sicher serialisierten Texte vor dem
     * Component-Aufruf entstehen.
     */
    $deleteConfirmationAction = '$dispatch("rt-confirm", {'
        . 'title: ' . \Illuminate\Support\Js::from(__('app.delete_user')) . ','
        . 'message: ' . \Illuminate\Support\Js::from(__('app.delete_user_confirm')) . ','
        . 'variant: "destructive",'
        . 'confirmLabel: ' . \Illuminate\Support\Js::from(__('app.delete')) . ','
        . 'action: () => $wire.deleteUser(' . (int) $item->id . ')'
        . '})';
@endphp

<x-ui.dropdown.anchor-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger orientation="vertical" />
    </x-slot>

    <x-slot name="content">
        @if (auth()->user()->canViewManagementDashboard())
            <x-dropdown-link
                href="{{ route(auth()->user()->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $item->id) }}"
                :can="'users.profiles.view'"
            >
                <i class="far fa-id-card mr-2"></i>
                {{ __('app.view_profile') }}
            </x-dropdown-link>
        @endif

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
            <x-dropdown-link wire:click.prevent="deactivateUser({{ $item->id }})" :can="'employees.create'" tone="warning">
                <i class="far fa-pause-circle mr-2"></i>
                {{ __('app.deactivate') }}
            </x-dropdown-link>
        @else
            <x-dropdown-link wire:click.prevent="activateUser({{ $item->id }})" :can="'employees.create'" tone="success">
                <i class="far fa-play-circle mr-2"></i>
                {{ __('app.activate') }}
            </x-dropdown-link>
        @endif

        @can('employees.delete')
            @if ((int) $item->id !== (int) auth()->id() && ! $item->isSuperAdmin())
                <x-dropdown-link
                    x-on:click.prevent="{{ $deleteConfirmationAction }}"
                    tone="danger"
                >
                    <i class="far fa-trash-alt mr-2"></i>
                    {{ __('app.delete_user') }}
                </x-dropdown-link>
            @endif
        @endcan

    </x-slot>
</x-ui.dropdown.anchor-dropdown>
