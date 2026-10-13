@props(['seats', 'summary', 'previewId' => null, 'timezone' => 'Europe/Berlin'])
@php
    $staffingLabel = $summary['reserved'].' von '.$summary['required'].' Einsatzplätzen eingeplant, '.$summary['confirmed'].' bestätigt, '.max(0, (int) $summary['open']).' offen';
@endphp
<x-ui.dropdown.anchor-dropdown
    align="left"
    width="auto"
    :open-on-hover="true"
    :hover-open-delay="400"
    :hover-close-delay="180"
    :dropdown-id="$previewId"
    layer-group="service-staffing"
    content-role="dialog"
    content-label="Besetzung vergrößert"
    content-classes="rt-service-staffing-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    dropdown-classes="rt-service-staffing-preview"
    trigger-classes="rt-service-staffing__trigger-wrap"
    class="rt-service-staffing"
    data-service-staffing
>
    <x-slot:trigger>
        <div
            class="rt-service-staffing__trigger"
            role="button"
            tabindex="0"
            aria-label="{{ $staffingLabel }} · Besetzung vergrößern"
            data-table-row-ignore
            x-bind:data-service-preview-state="open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')"
            x-bind:class="hoverOpenTimer !== null && !open ? 'cursor-wait' : 'cursor-zoom-in'"
            @keydown.enter.prevent.stop="toggle()"
            @keydown.space.prevent.stop="toggle()"
        >
            <x-operations.service-staffing-content :seats="$seats" :summary="$summary" />
        </div>
    </x-slot:trigger>
    <x-slot:content>
        <button type="button" class="rt-service-staffing-preview__close" aria-label="Vergrößerte Besetzung schließen" data-dropdown-initial-focus @click.stop="close(true)">
            <i class="far fa-times" aria-hidden="true"></i>
        </button>
        <x-operations.service-staffing-content :seats="$seats" :summary="$summary" :timezone="$timezone" :expanded="true" />
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
