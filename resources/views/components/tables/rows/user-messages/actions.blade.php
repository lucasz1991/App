@props(['item'])

<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger />
    </x-slot>

    <x-slot name="content">
        {{-- Anzeigen --}}
        <x-dropdown-link href="javascript:void(0)"
                         wire:click="$dispatch('message-viewer:open', { messageId: {{ $item->id }} })">
            <i class="far fa-eye mr-2" aria-hidden="true"></i>
            {{ __('app.show') }}
        </x-dropdown-link>

        {{-- Als gelesen markieren, falls ungelesen --}}
        @if ((int) ($item->status ?? 0) === 1)
            <x-dropdown-link href="javascript:void(0)"
                             wire:click="markAsRead({{ $item->id }})">
                <i class="far fa-envelope-open mr-2" aria-hidden="true"></i>
                {{ __('app.mark_as_read') }}
            </x-dropdown-link>
        @endif

        {{-- Loeschen --}}
        <x-dropdown-link href="javascript:void(0)"
                         tone="danger"
                         wire:click="deleteMessage({{ $item->id }})">
            <i class="far fa-trash-alt mr-2" aria-hidden="true"></i>
            {{ __('app.delete') }}
        </x-dropdown-link>
    </x-slot>
</x-dropdown>
