<x-ui.buttons.button-basic type="button" mode="link" class="rt-calendar-shift !block !h-auto w-full !whitespace-normal !text-left" wire:click="openDetails('order',{{ $event->publication_id ?? $event->id }})">
    <span class="block text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $event->starts->format('d.m. H:i') }} – {{ $event->ends->format($event->starts->isSameDay($event->ends)?'H:i':'d.m. H:i') }}</span>
    <strong class="mt-1 flex items-start gap-2 break-words text-sm"><x-customer-portal.icon name="train" />{{ $event->title }}</strong>
    <span class="rt-customer-portal__status mt-2" data-state="{{ $event->status }}">{{ \App\Livewire\CustomerPortal\Workspace::statusLabel($event->status) }}</span>
</x-ui.buttons.button-basic>
