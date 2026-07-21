@props(['item'])

<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger />
    </x-slot>
    <x-slot name="content">
        <x-dropdown-link href="javascript:void(0)" wire:click="toggleMailDetails({{ $item->id }})"><i class="far fa-eye mr-2"></i>{{ __('app.show') }}</x-dropdown-link>
        <x-dropdown-link href="javascript:void(0)" wire:click="resendMail({{ $item->id }})"><i class="far fa-redo mr-2"></i>{{ __('app.resend') }}</x-dropdown-link>
        @if (Auth::user()->isAdmin())
            <x-dropdown-link href="javascript:void(0)" wire:click="sendMessageToSuperAdmin({{ $item->id }})"><i class="far fa-paper-plane mr-2"></i>SuperAdmin Test</x-dropdown-link>
        @endif
    </x-slot>
</x-dropdown>
