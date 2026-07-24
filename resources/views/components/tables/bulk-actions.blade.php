@props([
    'count' => 0,
    'selectAll' => null,        // Name der wire:click-Methode fuer "alle markieren/entfernen"
    'selectAllTitle' => null,
    'align' => 'left',
    'width' => '56',
])

@php
    $count = (int) $count;
    $isEmpty = $count === 0;
    $selectAllTitle = $selectAllTitle ?? __('app.select_all');
@endphp

<div class="flex items-center gap-2" data-tables-bulk>
    {{-- Alle markieren / nicht markieren --}}
    <button
        type="button"
        @if($selectAll) wire:click="{{ $selectAll }}" @endif
        title="{{ $selectAllTitle }}"
        aria-label="{{ $selectAllTitle }}"
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
        </svg>
    </button>

    {{-- Massenaktionen-Dropdown --}}
    <x-ui.dropdown.anchor-dropdown
        :align="$align"
        :width="$width"
        content-classes="bg-rt-surface p-1.5 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    >
        <x-slot:trigger>
            <button
                type="button"
                @disabled($isEmpty)
                aria-label="{{ __('app.bulk_actions') }}"
                title="{{ __('app.bulk_actions') }}"
                @class([
                    'group flex h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3.5 text-sm font-medium text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text',
                    'hover:border-rt-accent/50 focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:hover:border-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20' => ! $isEmpty,
                    'cursor-not-allowed opacity-50' => $isEmpty,
                ])
            >
                <svg class="h-[18px] w-[18px] shrink-0 text-rt-muted dark:text-rt-dark-muted" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.005 11.19V12l6.998 4.042L19 12v-.81M5 16.15v.81L11.997 21l6.998-4.042v-.81M12.003 3 5.005 7.042l6.998 4.042L19 7.042 12.003 3Z" />
                </svg>
                <span class="hidden whitespace-nowrap sm:inline">{{ __('app.bulk_actions') }}</span>
                @unless($isEmpty)
                    <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rt-red px-1.5 py-0.5 text-xs font-bold leading-none text-white">
                        {{ $count }}
                    </span>
                @endunless
                <i class="far fa-chevron-down text-xs text-rt-muted transition-transform dark:text-rt-dark-muted" :class="open && 'rotate-180'" aria-hidden="true"></i>
            </button>
        </x-slot:trigger>

        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
</div>
