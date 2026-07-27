@props([
    'label',
    'icon',
    'tone' => 'quiet',
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'h-8 w-8',
        'md' => 'h-10 w-10',
        'lg' => 'h-11 w-11',
    ];
@endphp

<button
    type="button"
    title="{{ $label }}"
    aria-label="{{ $label }}"
    {{ $attributes->class([
        'rt-chat-icon-button inline-flex shrink-0 items-center justify-center rounded-xl text-sm',
        'rt-chat-icon-button--accent' => $tone === 'accent',
        'rt-chat-icon-button--danger' => $tone === 'danger',
        'rt-chat-icon-button--quiet' => $tone === 'quiet',
        $sizes[$size] ?? $sizes['md'],
    ]) }}
>
    <i class="{{ $icon }}" aria-hidden="true"></i>
</button>
