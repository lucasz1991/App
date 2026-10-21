@props(['type' => 'button'])

<button
    type="{{ $type }}"
    x-rt-launcher
    data-rt-floating-launcher
    {{ $attributes->class('rt-floating-launcher') }}
>
    {{ $slot }}
</button>
