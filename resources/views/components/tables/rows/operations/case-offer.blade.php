@foreach($columnsMeta as $column)
    <div class="rt-table-cell" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('case')<x-ui.buttons.button-basic type="button" mode="link" wire:click="select({{ $item->id }})">{{ $item->case_title }}</x-ui.buttons.button-basic><p class="ops-muted">{{ $item->case_customer }}</p>@break
            @case('revision')<span>{{ $item->kind === 'amendment' ? 'Nachtrag' : 'Angebot' }} · {{ $item->revision }}</span>@break
            @case('total')<span class="tabular-nums">{{ number_format($item->total_cents / 100,2,',','.') }} €</span>@break
            @case('status')<x-operations.status :value="$item->status" />@break
        @endswitch
    </div>
@endforeach
