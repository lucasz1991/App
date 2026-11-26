@php
    $ordersRangeLabel = \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.Y').' – '.\Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y');
@endphp
<x-ui.dropdown.anchor-dropdown align="right" width="auto" offset="6" :max-height="448"
    dropdown-id="orders-period-{{ $this->getId() }}" layer-group="operations-orders"
    content-role="dialog" content-label="Auftragszeitraum anpassen" dropdown-classes="rt-shift-plan-range-dropdown"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text" data-orders-period-control>
    <x-slot:trigger>
        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-shift-plan-period-trigger rt-orders-period-trigger"
            aria-label="Auftragszeitraum auswählen: {{ $ordersRangeLabel }}" title="{{ $ordersRangeLabel }}" data-orders-period-trigger>
            <i class="far fa-calendar-alt" aria-hidden="true"></i>
            <span><strong class="rt-calendar-period">{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }}</strong></span>
            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <x-ui.forms.date-range-picker from-model="rangeFrom" until-model="rangeTo" apply-action="applyPeriod" :today="now($displayTimezone)->toDateString()" :max-days="94" :reset-on-open="true"
            x-on:date-range-applied="close(true)" x-on:date-range-cancel="close(true)" />
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
