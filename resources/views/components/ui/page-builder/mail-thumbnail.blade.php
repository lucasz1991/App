@props(['url', 'name', 'version'])

<button type="button" class="rt-mail-library-thumbnail"
    aria-label="Vorschau: {{ $name }} · Version {{ $version }}" aria-haspopup="dialog"
    data-preview-url="{{ $url }}" data-preview-name="{{ $name }}" data-preview-version="{{ $version }}"
    x-on:mouseenter="if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) { previewTimer = setTimeout(() => openMailPreview($el), 450) }"
    x-on:mouseleave="clearTimeout(previewTimer)"
    x-on:blur="clearTimeout(previewTimer)"
    x-on:click="openMailPreview($el)">
    <span class="rt-mail-library-thumbnail__canvas" aria-hidden="true" inert>
        <x-ui.preview.frame :src="$url" :title="'Miniatur: '.$name" />
    </span>
    <span class="rt-mail-library-thumbnail__hint" aria-hidden="true"><i class="far fa-search-plus"></i></span>
</button>
