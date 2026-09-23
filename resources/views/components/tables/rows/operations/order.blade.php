@php
    $orderStatus = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
@endphp
<div class="min-w-0 px-2 py-1.5">
    <span class="rt-disposition-record-code">{{ $item->order_number }}</span>
    <x-ui.buttons.button-basic mode="link" type="button" wire:click="openDetails({{ $item->id }})" class="rt-disposition-record-title min-h-11 text-left font-semibold">
        <span class="break-words">{{ $item->title }}</span>
    </x-ui.buttons.button-basic>
    <span class="rt-disposition-record-meta">{{ $item->customer?->company_name ?? 'Kein Kunde hinterlegt' }}</span>
</div>
<div class="rt-disposition-cell--secondary min-w-0 px-2 py-1.5">
    <span class="block text-xs tabular-nums text-rt-text dark:text-rt-dark-text">
        {{ $item->starts_at?->format('d.m.Y H:i') ?? 'Beginn offen' }}
        <span class="rt-disposition-record-meta">bis {{ $item->ends_at?->format('d.m.Y H:i') ?? 'Ende offen' }}</span>
    </span>
    <span class="rt-disposition-record-meta">{{ $item->location_name ?: ($item->city ?: 'Einsatzort offen') }}</span>
</div>
<div class="rt-disposition-cell--secondary min-w-0 px-2 py-1.5">
    <span class="block text-xs font-semibold tabular-nums text-rt-text dark:text-rt-dark-text">{{ $item->required_staff }} {{ $item->required_staff === 1 ? 'Mitarbeiter/in' : 'Mitarbeitende' }}</span>
    <span class="rt-disposition-record-meta">{{ $item->shifts_count }} {{ $item->shifts_count === 1 ? 'Schicht angelegt' : 'Schichten angelegt' }}</span>
    @if($item->service_type)<span class="rt-disposition-record-meta">{{ $item->service_type }}</span>@endif
</div>
<div class="min-w-0 px-2 py-1.5">
    <x-operations.status :value="$orderStatus" :label="$item->status->label()" />
</div>
