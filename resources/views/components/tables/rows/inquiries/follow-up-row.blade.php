@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'title' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('title')<span class="rt-table-value">{{ $item->title }}</span><span class="rt-table-meta">{{ $item->assignee?->name ?? 'Nicht zugeordnet' }}</span>@break
            @case('due')<span class="tabular-nums {{ $item->status === 'open' && $item->due_at->isPast() ? 'text-red-600 dark:text-red-300' : '' }}">{{ $item->due_at->setTimezone($item->timezone)->format('d.m.Y H:i') }}</span>@break
            @case('status')@if($item->status === 'open')<x-ui.buttons.button-basic size="sm" wire:click="selectFollowUp({{ $item->id }})">Abschließen</x-ui.buttons.button-basic>@else<span>Erledigt</span><span class="rt-table-meta">{{ $item->completion_note }}</span>@endif @break
        @endswitch
    </div>
@endforeach
