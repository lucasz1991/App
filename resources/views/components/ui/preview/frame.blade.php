@props([
    'title',
    'src' => null,
    'tabindex' => '-1',
])

<iframe
    @if (filled($src))
        src="{{ $src }}"
    @endif
    title="{{ $title }}"
    sandbox=""
    referrerpolicy="no-referrer"
    loading="lazy"
    tabindex="{{ $tabindex }}"
    {{ $attributes->class('border-0 bg-white') }}
    data-ui-preview-frame
></iframe>
