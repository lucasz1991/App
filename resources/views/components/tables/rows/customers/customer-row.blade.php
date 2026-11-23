@php($hasMasterFields = array_key_exists('is_active', $item->getAttributes()))
<x-tables.cell label="Kunde" primary>
    <div class="rt-table-record customer-directory__identity">
    <x-operations.company-avatar :customer="$item" :size="40" :initials-only="true" />
    <div class="rt-table-record__body">
        <button type="button" wire:click="selectCustomer({{ $item->id }})" x-on:keydown.enter.stop class="flex min-h-10 max-w-full flex-col justify-center text-left text-rt-text hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-text" title="Kundenprofil öffnen: {{ $item->company_name }}">
            <span class="block w-full truncate font-semibold">{{ $item->company_name }}</span>
            @if($hasMasterFields)<span class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->customer_number }}</span>@endif
        </button>
    </div>
    </div>
</x-tables.cell>
@if($hasMasterFields)
    <x-tables.cell label="Ansprechpartner / Kontakt" hide-on="md">
        <p class="truncate text-sm text-rt-text dark:text-rt-dark-text">{{ $item->contact_name ?: '—' }}</p>
        <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted" title="{{ $item->email ?: $item->phone }}">{{ $item->email ?: ($item->phone ?: '—') }}</p>
        @if($item->email && $item->phone)<p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->phone }}</p>@endif
    </x-tables.cell>
    <x-tables.cell label="Ort" hide-on="md">
        <span class="block truncate text-sm text-rt-muted dark:text-rt-dark-muted">{{ $item->city ?: '—' }}</span>
    </x-tables.cell>
    <x-tables.cell label="Status" class="customer-directory__status">
        <span class="customer-directory__account-state" data-active="{{ $item->is_active?'true':'false' }}"><span aria-hidden="true"></span>{{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
    </x-tables.cell>
@endif
