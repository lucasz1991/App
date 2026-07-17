@props(['item'])

<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <button type="button"
                class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-center text-xl font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 transition focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900">
            &#x22EE;
        </button>
    </x-slot>

    <x-slot name="content">
        {{-- Anzeigen --}}
        <x-dropdown-link href="javascript:void(0)"
                         wire:click="showMessage({{ $item->id }})">
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
                         class="!text-red-600 dark:!text-red-400"
                         wire:click="deleteMessage({{ $item->id }})">
            <i class="far fa-trash-alt mr-2" aria-hidden="true"></i>
            {{ __('app.delete') }}
        </x-dropdown-link>
    </x-slot>
</x-dropdown>
