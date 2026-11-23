@php($current = $item->versions->firstWhere('file_id', $item->file?->id))
@foreach($columnsMeta as $column)<div class="rt-table-cell {{ $column['key'] === 'type' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @switch($column['key'])
        @case('type')<x-ui.buttons.button-basic mode="link" wire:click="showHistory('{{ $item->document_type }}')">{{ \App\Models\EmployeeDocumentRequirement::TYPES[$item->document_type] ?? $item->document_type }}</x-ui.buttons.button-basic>@break
        @case('file')@if($item->file)<x-ui.buttons.button-basic size="sm" wire:click="download('{{ $item->document_type }}')"><i class="far fa-download" aria-hidden="true"></i>{{ $item->file->name }}</x-ui.buttons.button-basic>@else<span>Zurückgezogen</span>@endif @break
        @case('ack')@if($current?->acknowledged_at)<span>{{ $current->acknowledged_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }}</span>@elseif($current && $item->file)<x-ui.buttons.button-basic size="sm" wire:click="acknowledge({{ $current->id }})">Zur Kenntnis genommen</x-ui.buttons.button-basic>@else<span>—</span>@endif @break
    @endswitch
</div>@endforeach
