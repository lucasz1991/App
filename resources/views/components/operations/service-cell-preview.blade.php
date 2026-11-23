@props([
    'label',
    'previewId',
    'kind' => null,
    // Eigene Hüllklasse für Dropdown und Fläche (z. B. rt-service-staffing-preview für die Besetzung).
    'previewClass' => 'rt-service-cell-preview',
    'triggerLabel' => null,
    'closeLabel' => null,
    // false: Inhalt ohne div.rt-service-cell-preview__content (Besetzung bringt eigene Hülle mit).
    'contentWrapper' => true,
])
<x-ui.dropdown.anchor-dropdown
    align="left"
    width="auto"
    :open-on-hover="true"
    :hover-open-delay="400"
    :hover-close-delay="180"
    :dropdown-id="$previewId"
    layer-group="service-staffing"
    content-role="dialog"
    :content-label="$label.' vergrößert'"
    :content-classes="$previewClass.'__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text'"
    :dropdown-classes="$previewClass"
    trigger-classes="rt-service-staffing__trigger-wrap"
    {{ $attributes->class('rt-service-staffing') }}
    :data-service-cell-preview="$kind"
>
    <x-slot:trigger>
        <div class="rt-service-staffing__trigger" role="button" tabindex="0" aria-label="{{ $triggerLabel ?? $label.' vergrößern' }}" data-table-row-ignore
            x-bind:data-service-preview-state="open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')"
            x-bind:class="hoverOpenTimer !== null && !open ? 'cursor-wait' : 'cursor-zoom-in'"
            @keydown.enter.prevent.stop="toggle()" @keydown.space.prevent.stop="toggle()">
            {{ $trigger }}
        </div>
    </x-slot:trigger>
    <x-slot:content>
        <button type="button" class="rt-service-staffing-preview__close" aria-label="{{ $closeLabel ?? $label.' schließen' }}" data-dropdown-initial-focus @click.stop="close(true)"><i class="far fa-times" aria-hidden="true"></i></button>
        @if($contentWrapper)
            <div class="rt-service-cell-preview__content" data-service-preview-kind="{{ $kind }}">{{ $content }}</div>
        @else
            {{ $content }}
        @endif
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
