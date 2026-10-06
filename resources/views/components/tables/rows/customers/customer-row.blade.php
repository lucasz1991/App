@php($hasMasterFields = array_key_exists('is_active', $item->getAttributes()))
<div role="cell" data-rt-table-label="Kunde" class="rt-table-cell rt-table-cell--primary min-w-0">
    <div class="rt-table-record pr-12 md:pr-0">
    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-building"></i></span>
    <div class="rt-table-record__body">
        <button type="button" wire:click="selectCustomer({{ $item->id }})" class="flex min-h-10 max-w-full flex-col justify-center text-left text-rt-text hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-text" title="Kundenprofil öffnen: {{ $item->company_name }}">
            <span class="block w-full truncate font-semibold">{{ $item->company_name }}</span>
            @if($hasMasterFields)<span class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->customer_number }}</span>@endif
        </button>
    </div>
    </div>
</div>
@if($hasMasterFields)
    <div role="cell" data-rt-table-label="Kontakt" class="rt-table-cell hidden min-w-0 md:block">
        <p class="truncate text-sm text-rt-text dark:text-rt-dark-text">{{ $item->contact_name ?: '—' }}</p>
        <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted" title="{{ $item->email ?: $item->phone }}">{{ $item->email ?: ($item->phone ?: '—') }}</p>
    </div>
    <div role="cell" data-rt-table-label="Ort" class="rt-table-cell hidden min-w-0 md:block">
        <span class="block truncate text-sm text-rt-muted dark:text-rt-dark-muted">{{ $item->city ?: '—' }}</span>
    </div>
    <div role="cell" data-rt-table-label="Status" class="rt-table-cell min-w-0">
        <x-ui.badge :color="$item->is_active ? 'emerald' : 'slate'"><i class="far {{ $item->is_active ? 'fa-check-circle' : 'fa-pause-circle' }}" aria-hidden="true"></i>{{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}</x-ui.badge>
    </div>
@endif
