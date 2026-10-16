@props(['type' => 'button'])

<button
    type="{{ $type }}"
    data-rt-floating-launcher
    {{ $attributes->class('rt-floating-launcher') }}
>
    {{ $slot }}
</button>
