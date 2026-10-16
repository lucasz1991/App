@props([
    'label' => null,
    'role' => null,
])

<section
    @if(filled($label)) aria-label="{{ $label }}" @endif
    @if(filled($role)) role="{{ $role }}" @endif
    {{ $attributes->class('rt-floating-window') }}
>
    @isset($header)
        <header {{ $header->attributes->class('rt-floating-window__header') }}>
            {{ $header }}
        </header>
    @endisset

    {{ $slot }}
</section>
