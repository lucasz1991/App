@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2">
    @if($column['key'] === 'name')
        <span class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $item->name }}</span>
    @elseif($column['key'] === 'eligibility')
        @forelse($item->planning_issues as $issue)
            <p class="text-sm text-amber-800 dark:text-amber-300">{{ $issue['message'] }}</p>
        @empty
            <x-operations.status value="approved" label="Geeignet" />
        @endforelse
    @else
        <x-ui.buttons.button-basic wire:click="chooseCandidate({{ $item->id }})" :disabled="count($item->planning_issues) > 0 ? true : null" wire:loading.attr="disabled" :aria-label="$item->name.' auswählen'">Auswählen</x-ui.buttons.button-basic>
    @endif
</div>
@endforeach
