@foreach($columnsMeta as $column)<div class="rt-table-cell {{ $column['key'] === 'revision' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @switch($column['key'])
        @case('revision')<span>Version {{ $item->revision }}</span><span class="rt-table-meta">{{ $item->withdrawn_at ? 'Zurückgezogen' : $item->created_at->format('d.m.Y H:i') }}</span>@break
        @case('file')<x-ui.buttons.button-basic size="sm" wire:click="downloadVersion({{ $item->id }})">{{ $item->snapshot['name'] }}</x-ui.buttons.button-basic>@break
        @case('ack')<span>{{ $item->acknowledged_at?->format('d.m.Y H:i') ?? 'Offen' }}</span>@break
    @endswitch
</div>@endforeach
