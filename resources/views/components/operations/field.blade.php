@props(['label', 'model', 'type' => 'text', 'wide' => false])
@php
    $fieldId = 'ops-'.str_replace('.', '-', $model);
@endphp
<div @class(['min-w-0 space-y-1.5', 'ops-full' => $wide])>
    <x-ui.forms.label :for="$fieldId" :value="$label" />
    @if($type === 'select')
        <x-ui.forms.select :id="$fieldId" :aria-label="$label" wire:model="{{ $model }}" {{ $attributes }}>{{ $slot }}</x-ui.forms.select>
    @elseif($type === 'textarea')
        <x-ui.forms.textarea :id="$fieldId" wire:model="{{ $model }}" {{ $attributes }} />
    @elseif($type === 'date')
        <x-ui.forms.date-field :id="$fieldId" :aria-label="$label" wire:model="{{ $model }}" {{ $attributes }} />
    @elseif($type === 'datetime-local')
        <x-ui.forms.date-time-field :id="$fieldId" :aria-label="$label" wire:model="{{ $model }}" {{ $attributes }} />
    @elseif($type === 'number')
        @php
            $numberStep = (string) $attributes->get('step', '1');
            $numberFraction = explode('.', rtrim(rtrim(number_format((float) $numberStep, 8, '.', ''), '0'), '.'), 2)[1] ?? '';
            $numberDecimals = (int) $attributes->get('decimals', strlen($numberFraction));
        @endphp
        <x-ui.forms.number-input :id="$fieldId" :decimals="$numberDecimals" :separator="$attributes->get('separator', '.')" wire:model="{{ $model }}" {{ $attributes->except(['decimals', 'separator']) }} />
    @else
        <x-ui.forms.input :id="$fieldId" :type="$type" wire:model="{{ $model }}" {{ $attributes }} />
    @endif
    <x-ui.forms.input-error :for="$model" />
</div>
