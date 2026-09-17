<div class="min-w-0 px-2 py-2">
    <x-ui.buttons.button-basic mode="link" class="!whitespace-normal !text-left" wire:click="openCalendarEvent('{{ $item->eventId }}')" data-personal-event="{{ $item->eventId }}"><i class="far {{ $item->kind === 'shift' ? 'fa-train' : 'fa-calendar-minus' }}" aria-hidden="true"></i>{{ $item->title }}</x-ui.buttons.button-basic>
    @if($item->kind === 'shift')<p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->record->shift->location_name }} · {{ $item->record->shift->role_name }}</p>@endif
</div>
<div class="px-2 py-2 text-sm tabular-nums">{{ $item->starts->format('d.m.Y H:i') }}<span class="block text-xs text-rt-muted dark:text-rt-dark-muted">bis {{ $item->ends->format('d.m.Y H:i') }}</span></div>
<div class="px-2 py-2"><x-operations.status :value="$item->status" />@if($item->kind === 'shift' && $item->record->plan_is_stale)<p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Planänderung in Prüfung</p>@endif</div>
