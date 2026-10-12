@props(['label', 'previewId', 'kind' => null])
<x-ui.dropdown.anchor-dropdown
    align="left"
    width="auto"
    :open-on-hover="true"
    :hover-open-delay="600"
    :hover-close-delay="180"
    :dropdown-id="$previewId"
    layer-group="service-staffing"
    content-role="dialog"
    :content-label="$label.' vergrößert'"
    content-classes="rt-service-cell-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    dropdown-classes="rt-service-cell-preview"
    trigger-classes="rt-service-staffing__trigger-wrap"
    class="rt-service-staffing"
    :data-service-cell-preview="$kind"
>
    <x-slot:trigger>
        <div class="rt-service-staffing__trigger" role="button" tabindex="0" aria-label="{{ $label }} vergrößern" data-table-row-ignore
            x-bind:data-service-preview-state="open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')"
            x-bind:style="{ '--rt-service-hover-wait': hoverOpenDelay + 'ms' }"
            @keydown.enter.prevent.stop="toggle()" @keydown.space.prevent.stop="toggle()">
            {{ $trigger }}
        </div>
    </x-slot:trigger>
    <x-slot:content>
        <button type="button" class="rt-service-staffing-preview__close" aria-label="{{ $label }} schließen" data-dropdown-initial-focus @click.stop="close(true)"><i class="far fa-times" aria-hidden="true"></i></button>
        <div class="rt-service-cell-preview__content" data-service-preview-kind="{{ $kind }}">{{ $content }}</div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
