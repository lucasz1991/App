@foreach($columnsMeta as $column)
<div class="rt-table-cell {{ $column['key'] === 'name' ? 'rt-table-cell--primary' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}">
    @if($column['key'] === 'name')
        <x-user.person-anchor-preview :user="$item" :show-presence="false" :size="8" />
    @elseif($column['key'] === 'eligibility')
        <div>
            @forelse($item->planning_issues as $issue)
                <p class="text-sm text-amber-800 dark:text-amber-300">{{ $issue['message'] }}</p>
            @empty
                <x-operations.status value="approved" label="Geeignet" />
            @endforelse
        </div>
    @else
        <x-ui.buttons.button-basic type="button" wire:click="chooseCandidate({{ $item->id }})" :disabled="count($item->planning_issues) > 0 ? true : null" wire:loading.attr="disabled" :aria-label="$item->name.' auswählen'">Auswählen</x-ui.buttons.button-basic>
    @endif
</div>
@endforeach
