{{--
    Versionszeile eines Mitarbeiterdokuments (Personalakte und Mitarbeiterprofil).
    Spalten: revision, file (downloadVersion(id)) bzw. requirement_file
    (download(typ, id)), status, ack. Zeiten immer in der Anzeige-Zeitzone.
--}}
@php
    $timezone = config('operations.display_timezone');
    $hasStatusColumn = collect($columnsMeta)->contains(fn ($column) => $column['key'] === 'status');
@endphp
@foreach($columnsMeta as $column)
    <x-tables.cell :column="$column" :primary="$column['key'] === 'revision'">
        @switch($column['key'])
            @case('revision')<span>Version {{ $item->revision }}</span><span class="rt-table-meta">{{ $item->withdrawn_at && ! $hasStatusColumn ? 'Zurückgezogen' : $item->created_at->copy()->setTimezone($timezone)->format('d.m.Y H:i') }}</span>@break
            @case('file')<x-ui.buttons.button-basic size="sm" wire:click="downloadVersion({{ $item->id }})">{{ $item->snapshot['name'] }}</x-ui.buttons.button-basic>@break
            @case('requirement_file')<x-ui.buttons.button-basic size="sm" wire:click="download('{{ $item->requirement->document_type }}', {{ $item->id }})">{{ $item->snapshot['name'] }}</x-ui.buttons.button-basic>@break
            @case('status')<span>{{ $item->withdrawn_at ? 'Zurückgezogen' : ($item->acknowledged_at ? 'Kenntnisnahme dokumentiert' : 'Gespeichert') }}</span>@break
            @case('ack')<span>{{ $item->acknowledged_at?->copy()->setTimezone($timezone)->format('d.m.Y H:i') ?? 'Offen' }}</span>@break
        @endswitch
    </x-tables.cell>
@endforeach
