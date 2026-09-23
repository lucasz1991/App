@foreach($columnsMeta as $column)
<div class="rt-table-cell {{ $column['key'] === 'name' ? 'rt-table-cell--primary' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}">
    @if($column['key'] === 'name')
        <div>
            @if($item->user)
                <x-user.person-anchor-preview :user="$item->user" :show-presence="false" :size="8" />
            @else
                <span class="rt-table-meta">Mitarbeiter nicht mehr verfügbar</span>
            @endif
            @foreach($item->planning_issues as $issue)<p class="mt-1 text-xs text-red-700 dark:text-red-300">{{ $issue['message'] }}</p>@endforeach
        </div>
    @elseif($column['key'] === 'response')
        <div>
            @if($item->plan_revision > 0 && $item->plan_revision === $item->shift->published_revision)
                <x-operations.status :value="$item->status->value" :label="$item->status->label()" />
                <span class="rt-table-meta">Revision {{ $item->plan_revision }}@if($item->responded_at) · {{ $item->responded_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') }}@endif</span>
            @else
                <span class="rt-table-meta">Noch nicht veröffentlicht</span>
            @endif
        </div>
    @elseif($column['key'] === 'opened')
        <span class="rt-table-meta tabular-nums">{{ $item->plan_opened_at ? $item->plan_opened_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') : 'Noch nicht geöffnet' }}</span>
    @else
        @if($item->status->blocksAvailability())
            <x-ui.buttons.button-basic type="button" wire:click="removeAssignment({{ $item->id }})" wire:confirm="Zuweisung wirklich entfernen?" wire:loading.attr="disabled" :aria-label="$item->user?->name.' aus der Schicht entfernen'">Entfernen</x-ui.buttons.button-basic>
        @endif
    @endif
</div>
@endforeach
