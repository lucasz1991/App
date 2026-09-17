@props(['disabled' => false, 'readonly' => false, 'required' => false, 'ariaLabel' => null])
@php
    $wireModel = $attributes->wire('model')->value();
    $wireLive = $attributes->wire('model')->hasModifier('live');
    $fieldId = $attributes->get('id') ?: 'date-time-'.str_replace('.', '-', $wireModel ?: \Illuminate\Support\Str::random(8));
    $controlLabel = $ariaLabel ?: $attributes->get('aria-label', 'Datum und Uhrzeit');
    $outer = $attributes->whereDoesntStartWith('wire:model')->except(['id', 'name', 'value', 'aria-label', 'aria-describedby', 'aria-invalid', 'min', 'max', 'step']);
@endphp
<div
    x-data="rtDateTimeField({ value: @if($wireModel) $wire.entangle({{ \Illuminate\Support\Js::from($wireModel) }}){{ $wireLive ? '.live' : '' }} @else {{ \Illuminate\Support\Js::from((string) $attributes->get('value', '')) }} @endif })"
    x-modelable="value"
    {{ $outer->merge(['class'=>'rt-date-time-field']) }}
    data-rt-date-time-field
>
    <x-ui.forms.date-field :id="$fieldId" x-model="datePart" :disabled="$disabled" :readonly="$readonly" :aria-label="$controlLabel.' · Datum'" :required="$required" :aria-invalid="$attributes->get('aria-invalid')" :aria-describedby="$attributes->get('aria-describedby')" :min="$attributes->get('min') ? substr($attributes->get('min'), 0, 10) : null" :max="$attributes->get('max') ? substr($attributes->get('max'), 0, 10) : null" />
    <x-ui.forms.input
        :id="$fieldId.'-time'" type="time" x-ref="time" x-model="timePart"
        :disabled="$disabled" :readonly="$readonly" :required="$required"
        :aria-label="$controlLabel.' · Uhrzeit'" :aria-invalid="$attributes->get('aria-invalid')" :aria-describedby="$attributes->get('aria-describedby')"
        :step="$attributes->get('step', 60)" class="rt-date-time-field__time"
    />
    @if($attributes->has('name'))<input type="hidden" name="{{ $attributes->get('name') }}" :value="value" @disabled($disabled) />@endif
</div>
