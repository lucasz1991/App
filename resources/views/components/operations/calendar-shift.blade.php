@props(['shift', 'compact' => false])
@php
    // Gleiche Aufbereitung wie x-operations.shift-card; Zeitzone aus der Kalenderansicht.
    $card = \App\Support\Operations\ShiftCardData::from($shift, $shift->calendar_starts->getTimezone()->getName());
@endphp
<x-ui.buttons.button-basic
    mode="link"
    type="button"
    x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $shift->id }} })"
    aria-haspopup="dialog"
    data-calendar-reveal-item
    class="rt-calendar-shift {{ $compact ? 'rt-calendar-shift-compact' : '' }} !block !h-auto w-full !whitespace-normal !text-left"
    data-calendar-shift="{{ $shift->id }}"
    data-calendar-staffing="{{ $card->open > 0 ? 'open' : 'staffed' }}"
    data-calendar-state="{{ $card->state() }}"
>
    <span class="rt-calendar-shift-time block text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $card->starts->format($compact && $card->starts->isSameDay($card->ends) ? 'H:i' : 'd.m. H:i') }} – {{ $card->ends->format($card->starts->isSameDay($card->ends) ? 'H:i' : 'd.m. H:i') }}</span>
    <span class="rt-calendar-shift-title mt-1 block break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $shift->title }}</span>
    <span class="rt-calendar-shift-order mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->order?->order_number }}@unless($compact) · {{ $shift->order?->title }}@endunless</span>
    @unless($compact)
        <span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->location_name ?: '—' }}</span>
    @endunless
    <span class="rt-calendar-shift-status mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span @class(['rt-calendar-shift-staffing font-semibold tabular-nums', 'text-rt-red dark:text-rt-dark-accent' => $card->open > 0, 'text-rt-muted dark:text-rt-dark-muted' => $card->open === 0])>{{ $card->reserved }}/{{ $card->required }} eingeplant</span>
        <x-operations.status :value="$shift->status" />
    </span>
    @if($card->hasUnpublishedChanges())
        <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $card->publicationLabel() }}</span>
    @endif
</x-ui.buttons.button-basic>
