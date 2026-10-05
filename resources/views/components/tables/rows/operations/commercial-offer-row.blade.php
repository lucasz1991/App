@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'revision' ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('revision')<x-ui.buttons.button-basic mode="link" wire:click="select({{ $item->id }})">{{ $item->kind === 'amendment' ? 'Nachtrag' : 'Angebot' }} · Version {{ $item->revision }}</x-ui.buttons.button-basic>@break
            @case('total')<span class="tabular-nums">{{ number_format($item->total_cents / 100, 2, ',', '.') }} €</span>@break
            @case('status')<span>{{ ['draft'=>'Entwurf','offered'=>'Angeboten','accepted'=>'Zugesagt','rejected'=>'Abgelehnt'][$item->status] ?? $item->status }}</span>@break
        @endswitch
    </div>
@endforeach
