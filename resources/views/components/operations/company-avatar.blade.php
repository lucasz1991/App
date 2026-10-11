@props(['customer' => null, 'size' => 32, 'initialsOnly' => false, 'portal' => false])
@php
    $size = max(16, min(96, (int) $size));
    $logo = !$initialsOnly ? $customer?->logoUrl((bool) $portal) : null;
    $name = $customer?->company_name ?: 'Unternehmen';
    $initials = $customer?->company_initials ?: '?';
@endphp
<span {{ $attributes->class(['relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-rt-surface-muted font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70']) }}
    style="width:{{ $size }}px;height:{{ $size }}px;font-size:{{ max(10, (int) round($size * .34)) }}px;" title="{{ $name }}" aria-hidden="true" data-company-avatar>
    <span>{{ $initials }}</span>
    @if($logo)<img class="absolute inset-0 h-full w-full bg-white object-contain p-1" src="{{ $logo }}" alt="" loading="lazy" decoding="async" x-data x-on:error="$el.remove()" data-company-logo />@endif
</span>
