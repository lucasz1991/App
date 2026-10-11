@props(['status'])
@php
    $icon = match ($status) {
        \App\Enums\OrderStatus::Requested => 'fa-inbox',
        \App\Enums\OrderStatus::Confirmed => 'fa-check-circle',
        \App\Enums\OrderStatus::Planned => 'fa-calendar-check',
        \App\Enums\OrderStatus::InProgress => 'fa-play-circle',
        \App\Enums\OrderStatus::Completed => 'fa-circle-check',
        \App\Enums\OrderStatus::Invoiced => 'fa-file-invoice',
        \App\Enums\OrderStatus::Cancelled => 'fa-ban',
    };
@endphp
<span class="rt-service-status" data-service-status="{{ $status->value }}"><i class="far {{ $icon }}" aria-hidden="true"></i><span>{{ $status->label() }}</span></span>
