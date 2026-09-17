@props(['event', 'compact' => false])
<x-ui.buttons.button-basic
    mode="link"
    type="button"
    wire:click="openCalendarEvent('{{ $event->id }}')"
    class="rt-calendar-shift rt-personal-calendar-event {{ $compact ? 'rt-calendar-shift-compact' : '' }} !block !h-auto w-full !whitespace-normal !text-left"
    data-personal-event="{{ $event->id }}"
    data-event-kind="{{ $event->kind }}"
>
    <span class="block text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $event->starts->format('d.m. H:i') }} – {{ $event->ends->format($event->starts->isSameDay($event->ends) ? 'H:i' : 'd.m. H:i') }}</span>
    <span class="mt-1 block break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text"><i class="far {{ $event->kind === 'shift' ? 'fa-train' : 'fa-calendar-minus' }} mr-1" aria-hidden="true"></i>{{ $event->title }}</span>
    @if($event->kind === 'shift')
        <span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $event->record->shift->location_name ?: '—' }}</span>
        @unless($compact)<span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $event->record->shift->role_name }}</span>@endunless
    @endif
    <span class="mt-2 flex flex-wrap items-center gap-2 text-xs"><x-operations.status :value="$event->status" /></span>
    @if($event->kind === 'shift' && $event->record->plan_is_stale)
        <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted">Planänderung in Prüfung</span>
    @endif
</x-ui.buttons.button-basic>
