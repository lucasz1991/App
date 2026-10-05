@foreach($columnsMeta as $column)<div class="rt-table-cell {{ $column['key'] === 'revision' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @switch($column['key'])
        @case('revision')<span>Version {{ $item->revision }}</span><span class="rt-table-meta">{{ $item->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }}</span>@break
        @case('file')<x-ui.buttons.button-basic size="sm" wire:click="download('{{ $item->requirement->document_type }}', {{ $item->id }})">{{ $item->snapshot['name'] }}</x-ui.buttons.button-basic>@break
        @case('status')<span>{{ $item->withdrawn_at ? 'Zurückgezogen' : ($item->acknowledged_at ? 'Kenntnisnahme dokumentiert' : 'Gespeichert') }}</span>@break
    @endswitch
</div>@endforeach
