@props(['label', 'model', 'type' => 'text', 'wide' => false])
@php($fieldId = 'ops-'.str_replace('.', '-', $model))
<div @class(['ops-field', 'ops-full' => $wide])>
    <label for="{{ $fieldId }}">{{ $label }}</label>
    @if($type === 'select')
        <select id="{{ $fieldId }}" wire:model="{{ $model }}" {{ $attributes }}>{{ $slot }}</select>
    @elseif($type === 'textarea')
        <textarea id="{{ $fieldId }}" wire:model="{{ $model }}" {{ $attributes }}></textarea>
    @else
        <x-ui.forms.input :id="$fieldId" :type="$type" wire:model="{{ $model }}" {{ $attributes }} />
    @endif
</div>
