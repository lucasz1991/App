@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'title' ? 'rt-table-cell--primary' : '' }} {{ $column['key'] === 'status' ? 'rt-table-cell--status' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}" wire:key="inquiry-{{ $item->id }}-{{ $column['key'] }}">
        @switch($column['key'])
            @case('title')
                <div class="rt-table-record">
                    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-inbox"></i></span>
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow">{{ $item->number }}</span>
                        <x-ui.buttons.button-basic type="button" mode="link" class="rt-table-record__title" wire:click="select({{ $item->id }})" wire:loading.attr="disabled" wire:target="select">{{ $item->title }}</x-ui.buttons.button-basic>
                        <span class="rt-table-record__meta">@if($item->required_staff){{ $item->required_staff }} × @endif{{ $item->role_name ?: 'Tätigkeit noch offen' }}</span>
                    </div>
                </div>
                @break
            @case('customer')
                <div>
                    <span class="rt-table-value">{{ $item->customer?->company_name ?? 'Noch nicht zugeordnet' }}</span>
                    <span class="rt-table-meta">{{ ['email' => 'E-Mail', 'phone' => 'Telefon', 'portal' => 'Portal', 'manual' => 'Manuell'][$item->channel] ?? $item->channel }}</span>
                </div>
                @break
            @case('schedule')
                <div>
                    @if($item->starts_at)
                        <span class="rt-table-value tabular-nums">{{ $item->starts_at->format('d.m.Y') }}</span>
                        <span class="rt-table-meta tabular-nums">{{ $item->starts_at->format('H:i') }}@if($item->ends_at)–{{ $item->ends_at->format($item->starts_at->isSameDay($item->ends_at) ? 'H:i' : 'd.m. H:i') }}@endif</span>
                        <span class="rt-table-meta">{{ $item->timezone }}</span>
                    @else
                        <span class="rt-table-meta">Termin noch offen</span>
                    @endif
                    @if($item->location_name)<span class="rt-table-meta">{{ $item->location_name }}</span>@endif
                </div>
                @break
            @case('status')
                <x-operations.status :value="$item->status" />
                @break
        @endswitch
    </div>
@endforeach
