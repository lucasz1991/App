@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-2 {{ $hideClass($column['hideOn']) }}">
        @if($column['key'] === 'title')<p class="text-sm font-semibold">{{ $item->title }}</p>
        @elseif($column['key'] === 'choice')
            @if($item->eligible)<x-ui.buttons.button-basic type="button" size="sm" wire:click="selectCellShift({{ $item->id }},{{ $item->revision }})">Auswählen</x-ui.buttons.button-basic>
            @else @foreach($item->issues as $issue)<p class="ops-muted">{{ $issue['message'] }}</p>@endforeach @endif
        @else<span class="mr-1 text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span>{{ data_get($item,$column['key']) }}@endif
    </div>
@endforeach
