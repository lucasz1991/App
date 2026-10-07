@props([
    'id' => null,
    'label' => null,
    'model' => null,
    'change' => null,
    'size' => 'md',
    'disabled' => false,
    'labelInside' => false,
])

@php
    $inputId = $id ?: 'toggle-' . \Illuminate\Support\Str::random(6);
    $resolvedSize = in_array($size, ['sm', 'md'], true) ? $size : 'md';
@endphp

<label
    for="{{ $inputId }}"
    {{ $attributes->only('class')->class([
        'rt-ui-toggle group',
        'rt-ui-toggle--label-inside' => $labelInside,
        'cursor-pointer' => ! $disabled,
        'is-disabled' => $disabled,
    ]) }}
>
    <input
        id="{{ $inputId }}"
        type="checkbox"
        role="switch"
        @if($model) wire:model.live="{{ $model }}" @endif
        @if($change) @change="{{ $change }}" @endif
        @if($model) data-autosave-model="{{ $model }}" data-autosave-field-id="{{ $inputId }}" @endif
        {{ $disabled ? 'disabled' : '' }}
        {!! $attributes->except('class') !!}
        class="rt-ui-toggle__input peer sr-only"
    />

    <span
        data-toggle-control
        @if($model)
            data-autosave-field
            data-autosave-model="{{ $model }}"
            data-autosave-field-id="{{ $inputId }}"
            data-autosave-state="idle"
            data-autosave-visual
        @endif
        @if(! $labelInside) aria-hidden="true" @endif
        class="rt-ui-toggle-control rt-ui-toggle-control--{{ $resolvedSize }}"
    >
        @if($labelInside && $slot->isNotEmpty())
            <span class="rt-ui-toggle__label">{{ $slot }}</span>
        @elseif($label && $labelInside)
            <span class="rt-ui-toggle__label">{{ $label }}</span>
        @endif
    </span>

    @if($label && ! $labelInside)
        <span class="rt-ui-toggle__label">
            {{ $label }}
        </span>
    @endif
</label>
