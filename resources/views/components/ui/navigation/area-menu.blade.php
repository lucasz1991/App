@props(['options' => [], 'value' => null, 'action' => null, 'label' => 'Bereichsmenü', 'id' => null, 'navigate' => false, 'disabled' => false])

{{-- One native navigation instance: topbar on tablet/desktop, bottom dock on phones. --}}
<x-ui.buttons.multi-toggle
    :options="$options" :value="$value" :action="$action" :label="$label" :id="$id"
    :navigate="$navigate" :disabled="$disabled" :show-labels="true"
    {{ $attributes->class(['rt-area-menu'])->merge(['data-area-menu' => '']) }}
>
    <span class="rt-area-menu__indicator" aria-hidden="true" x-data="rtAreaMenu" wire:ignore></span>
    @if(auth()->check() && ! filter_var($disabled, FILTER_VALIDATE_BOOL))
        <x-ui.buttons.button-basic
            href="{{ route('chat') }}"
            wire:navigate
            class="rt-multi-toggle__button rt-area-menu__chat"
            data-area-menu-chat
            aria-controls="railtime-communication"
            aria-label="Chat im Vollbild öffnen"
            title="Chat im Vollbild öffnen"
        >
            <i class="far fa-comments" aria-hidden="true"></i>
            <span class="rt-multi-toggle__label">Chat</span>
        </x-ui.buttons.button-basic>
    @endif
</x-ui.buttons.multi-toggle>
