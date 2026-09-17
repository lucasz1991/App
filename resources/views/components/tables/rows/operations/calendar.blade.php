<div class="min-w-0 px-2 py-2">
    <x-ui.buttons.button-basic mode="link" class="!whitespace-normal !text-left" wire:click="openShift({{ $item->id }})">{{ $item->title }}</x-ui.buttons.button-basic>
    <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->order?->customer?->company_name }} · {{ $item->location_name }}</p>
</div>
<div class="px-2 py-2 text-sm tabular-nums">{{ $item->calendar_starts->format('d.m.Y H:i') }}<span class="block text-xs text-rt-muted">bis {{ $item->calendar_ends->format('d.m.Y H:i') }}</span></div>
<div class="px-2 py-2 text-sm tabular-nums">{{ $item->calendar_reserved }}/{{ $item->required_staff }} eingeplant</div>
<div class="px-2 py-2"><x-operations.status :value="$item->status->value" :label="$item->status->label()" /></div>
