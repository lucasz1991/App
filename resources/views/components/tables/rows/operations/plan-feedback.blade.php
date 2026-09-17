@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2">
    @if($column['key'] === 'name')
        <p class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $item->user?->name }}</p>
        @foreach($item->planning_issues as $issue)<p class="mt-1 text-xs text-red-700 dark:text-red-300">{{ $issue['message'] }}</p>@endforeach
    @elseif($column['key'] === 'response')
        <span class="mr-1 text-xs text-rt-muted md:hidden">Antwort:</span>
        @if($item->plan_revision > 0 && $item->plan_revision === $item->shift->published_revision)
            <x-operations.status :value="$item->status->value" :label="$item->status->label()" />
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Revision {{ $item->plan_revision }}@if($item->responded_at) · {{ $item->responded_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') }}@endif</p>
        @else
            <span class="ops-muted">Noch nicht veröffentlicht</span>
        @endif
    @elseif($column['key'] === 'opened')
        <span class="mr-1 text-xs text-rt-muted md:hidden">Im Kalender geöffnet:</span>
        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ $item->plan_opened_at ? $item->plan_opened_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') : '—' }}</p>
    @else
        @if($item->status->blocksAvailability())
            <x-ui.buttons.button-basic wire:click="removeAssignment({{ $item->id }})" wire:confirm="Zuweisung wirklich entfernen?" wire:loading.attr="disabled" :aria-label="$item->user?->name.' aus der Schicht entfernen'">Entfernen</x-ui.buttons.button-basic>
        @endif
    @endif
</div>
@endforeach
