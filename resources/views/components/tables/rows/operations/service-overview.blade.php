@php
    $previewPrefix = $tableKey === 'service-overview' ? 'service' : $tableKey;
    $isOrderRow = isset($item->requiredStaff);
@endphp
@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'service' ? 'rt-table-cell--primary' : '' }} {{ $column['key'] === 'status' ? 'rt-table-cell--status' : '' }} {{ in_array($column['key'], ['staffing', 'period', 'location', 'count'], true) ? 'rt-table-cell--service-staffing' : '' }} {{ $hideClass($column['hideOn']) }}" data-service-column="{{ $column['key'] }}" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('service')
                <div class="rt-table-record">
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow">{{ $item->order?->order_number }}</span>
                        @if($item->order)
                            <button type="button" wire:click="{{ $safeDetailAction }}({{ $item->id }})" wire:loading.attr="disabled" wire:target="{{ $safeDetailAction }}" class="rt-table-record__title text-left" aria-label="Leistungsdetails: {{ $item->order->title }}">{{ $item->order->title }}</button>
                        @else
                            <span class="rt-table-value">Leistung nicht verfügbar</span>
                        @endif
                    </div>
                </div>
                @break
            @case('customer')
                <div class="rt-service-customer"><x-operations.company-avatar :customer="$item->order?->customer" :size="30" /><span class="rt-table-value">{{ $item->order?->customer?->company_name ?: 'Kunde nicht hinterlegt' }}</span></div>
                @break
            @case('period')
                <x-operations.service-period-preview :start="$item->from" :end="$item->until" :preview-id="$previewPrefix.'-period-'.$item->id" />
                @break
            @case('location')
                <x-operations.service-location-preview :order="$item->order" :preview-id="$previewPrefix.'-location-'.$item->id" />
                @break
            @case('count')
                <x-operations.service-shifts-preview :item="$item" :timezone="$item->timezone" :preview-id="$previewPrefix.'-shifts-'.$item->id" :detail-action="$isOrderRow ? $safeDetailAction : null" :period-label="$isOrderRow ? 'Gesamter Auftrag' : 'Im Zeitraum'" :required-staff="$isOrderRow ? $item->requiredStaff : null" />
                @break
            @case('staffing')
                @if($isOrderRow && $item->count === 0)
                    <span class="rt-table-meta" data-order-staffing-unplanned>Noch keine Schichten</span>
                @elseif($isOrderRow && $item->summary['shifts'] === 0)
                    <span class="rt-table-meta" data-order-staffing-history>Keine aktiven Schichten</span>
                @else
                    <x-operations.service-staffing :seats="$item->staffingSeats" :summary="$item->summary" :timezone="$item->timezone" :preview-id="$previewPrefix.'-staffing-'.$item->id" />
                @endif
                @break
            @case('status')
                @if($item->order)<x-operations.service-status :status="$item->order->status" />@endif
                @break
        @endswitch
    </div>
@endforeach
