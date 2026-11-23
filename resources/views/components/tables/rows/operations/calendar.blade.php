<x-tables.cell label="Schicht / Kunde" primary>
    <x-ui.buttons.button-basic mode="link" class="!whitespace-normal !text-left" x-on:click.stop="$dispatch('operations-shift-detail-request', { id: {{ $item->id }} })">{{ $item->title }}</x-ui.buttons.button-basic>
    <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->order?->order_number }} · {{ $item->order?->title }}</p>
    <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->order?->customer?->company_name }} · {{ $item->location_name }}</p>
</x-tables.cell>
<x-tables.cell label="Zeitraum" class="text-sm tabular-nums">{{ $item->calendar_starts->format('d.m.Y H:i') }}<span class="block text-xs text-rt-muted">bis {{ $item->calendar_ends->format('d.m.Y H:i') }}</span></x-tables.cell>
<x-tables.cell label="Besetzung" class="text-sm tabular-nums">{{ $item->calendar_reserved }}/{{ $item->required_staff }} eingeplant</x-tables.cell>
<x-tables.cell label="Status" status><x-operations.status :value="$item->status" /></x-tables.cell>
