@php($hasMasterFields = array_key_exists('is_active', $item->getAttributes()))
<div role="cell" data-rt-table-label="Kunde" class="flex min-w-0 items-center gap-3 px-2 py-2">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted" aria-hidden="true"><i class="far fa-building"></i></span>
    <div class="min-w-0">
        <button type="button" wire:click="selectCustomer({{ $item->id }})" class="flex min-h-10 max-w-full flex-col justify-center text-left text-rt-text hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-text" title="Kundenprofil öffnen: {{ $item->company_name }}">
            <span class="block w-full truncate font-semibold">{{ $item->company_name }}</span>
            @if($hasMasterFields)<span class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->customer_number }}</span>@endif
        </button>
    </div>
</div>
@if($hasMasterFields)
    <div role="cell" data-rt-table-label="Kontakt" class="hidden min-w-0 px-2 py-2 md:block">
        <p class="truncate text-sm text-rt-text dark:text-rt-dark-text">{{ $item->contact_name ?: '—' }}</p>
        <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted" title="{{ $item->email ?: $item->phone }}">{{ $item->email ?: ($item->phone ?: '—') }}</p>
    </div>
    <div role="cell" data-rt-table-label="Ort" class="hidden min-w-0 px-2 py-2 md:block">
        <span class="block truncate text-sm text-rt-muted dark:text-rt-dark-muted">{{ $item->city ?: '—' }}</span>
    </div>
    <div role="cell" data-rt-table-label="Status" class="min-w-0 px-2 py-2">
        <x-ui.badge :color="$item->is_active ? 'emerald' : 'slate'"><i class="far {{ $item->is_active ? 'fa-check-circle' : 'fa-pause-circle' }}" aria-hidden="true"></i>{{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}</x-ui.badge>
    </div>
@endif
