@props(['shift', 'compact' => false])
<x-ui.buttons.button-basic
    mode="link"
    wire:click="openShift({{ $shift->id }})"
    class="rt-calendar-shift !block !h-auto w-full !whitespace-normal !text-left"
    data-calendar-shift="{{ $shift->id }}"
>
    <span class="block text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $shift->calendar_starts->format('d.m. H:i') }} – {{ $shift->calendar_ends->format($shift->calendar_starts->isSameDay($shift->calendar_ends) ? 'H:i' : 'd.m. H:i') }}</span>
    <span class="mt-1 block break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $shift->title }}</span>
    <span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->order?->order_number }}@unless($compact) · {{ $shift->order?->title }}@endunless</span>
    @unless($compact)
        <span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->location_name ?: '—' }}</span>
    @endunless
    <span class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span @class(['font-semibold tabular-nums', 'text-rt-red' => $shift->calendar_open > 0, 'text-rt-muted dark:text-rt-dark-muted' => $shift->calendar_open === 0])>{{ $shift->calendar_reserved }}/{{ $shift->required_staff }} eingeplant</span>
        <x-operations.status :value="$shift->status->value" :label="$shift->status->label()" />
    </span>
    @if($shift->revision && $shift->published_revision !== $shift->revision)
        <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht' }}</span>
    @endif
</x-ui.buttons.button-basic>
