@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @switch($column['key'])
            @case('service')<strong class="block break-words">{{ $item->customer }} · {{ $item->title }}</strong><span class="ops-muted block break-words text-xs">{{ $item->location }}</span>@break
            @case('period')<span class="block tabular-nums text-sm">{{ $item->starts_at->setTimezone($item->timezone)->format('d.m.Y H:i') }} – {{ $item->ends_at->setTimezone($item->timezone)->format('d.m.Y H:i') }}</span><span class="ops-muted text-xs">{{ $item->timezone }}</span>@break
            @case('state')<span class="ops-badge">{{ ['requested'=>'Offen','consented'=>'Zugestimmt','approved'=>'Freigegeben','declined'=>'Abgelehnt','canceled'=>'Zurückgezogen'][$item->status] ?? 'Zur Prüfung' }}</span>@break
            @case('actions')@if($item->can_respond)<x-ui.buttons.button-basic type="button" wire:click="open({{ $item->id }})" wire:loading.attr="disabled">Antworten</x-ui.buttons.button-basic>@endif @break
        @endswitch
    </div>
@endforeach
