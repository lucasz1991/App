@props([
    'label' => null,
    'role' => null,
    'dragPointerdown' => null,
    'dragPointermove' => null,
    'dragPointerup' => null,
    'dragPointercancel' => null,
    'dragLostpointercapture' => null,
    'dragKeydown' => null,
    'dragEnabled' => null,
    'dragLabel' => 'Fensterposition',
])

<section
    @if(filled($label)) aria-label="{{ $label }}" @endif
    @if(filled($role)) role="{{ $role }}" @endif
    @if(filled($dragPointermove)) x-on:pointermove.window="{{ $dragPointermove }}" @endif
    @if(filled($dragPointerup)) x-on:pointerup.window="{{ $dragPointerup }}" @endif
    @if(filled($dragPointercancel)) x-on:pointercancel.window="{{ $dragPointercancel }}" @endif
    @if(filled($dragLostpointercapture)) x-on:lostpointercapture.self="{{ $dragLostpointercapture }}" @endif
    {{ $attributes->class('rt-floating-window') }}
>
    @isset($header)
        <header
            @if(filled($dragPointerdown)) x-on:pointerdown="{{ $dragPointerdown }}" @endif
            @if(filled($dragKeydown)) x-on:keydown="{{ $dragKeydown }}" @endif
            @if(filled($dragEnabled)) :tabindex="{{ $dragEnabled }} ? 0 : -1" @endif
            @if(filled($dragPointerdown) || filled($dragKeydown)) role="group" aria-label="{{ $dragLabel }}" title="Ziehen oder mit Alt und Pfeiltasten in eine Ecke setzen" @endif
            {{ $header->attributes->class('rt-floating-window__header') }}
        >
            {{ $header }}
        </header>
    @endisset

    {{ $slot }}
</section>
