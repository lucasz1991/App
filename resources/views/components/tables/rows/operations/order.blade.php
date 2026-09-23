@php
    $orderStatus = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
@endphp
@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'title' ? 'rt-table-cell--primary' : '' }} {{ $column['key'] === 'status' ? 'rt-table-cell--status' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('title')
                <div class="rt-table-record">
                    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-clipboard-list"></i></span>
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow">{{ $item->order_number }}</span>
                        <x-ui.buttons.button-basic mode="link" type="button" wire:click="openDetails({{ $item->id }})" wire:loading.attr="disabled" wire:target="openDetails" class="rt-table-record__title">{{ $item->title }}</x-ui.buttons.button-basic>
                        <span class="rt-table-record__meta">{{ $item->customer?->company_name ?? 'Kein Kunde hinterlegt' }}</span>
                    </div>
                </div>
                @break
            @case('period')
                <div>
                    <span class="rt-table-value tabular-nums">{{ $item->starts_at?->format('d.m.Y H:i') ?? 'Beginn offen' }}</span>
                    <span class="rt-table-meta tabular-nums">bis {{ $item->ends_at?->format('d.m.Y H:i') ?? 'Ende offen' }}</span>
                    <span class="rt-table-meta">{{ $item->location_name ?: ($item->city ?: 'Einsatzort offen') }}</span>
                </div>
                @break
            @case('staff')
                <div>
                    <span class="rt-table-value tabular-nums">{{ $item->required_staff }} {{ $item->required_staff === 1 ? 'Mitarbeiter/in' : 'Mitarbeitende' }}</span>
                    <span class="rt-table-meta">{{ $item->shifts_count }} {{ $item->shifts_count === 1 ? 'Schicht angelegt' : 'Schichten angelegt' }}</span>
                    @if($item->service_type)<span class="rt-table-meta">{{ $item->service_type }}</span>@endif
                </div>
                @break
            @case('status')
                <x-operations.status :value="$orderStatus" :label="$item->status->label()" />
                @break
        @endswitch
    </div>
@endforeach
