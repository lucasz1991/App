@props([
    'eyebrow' => null,
    'title',
    'subtitle' => null,
    'icon' => null,
    'closeLabel' => 'Panel schließen',
    'closeAction' => null,
])
<header {{ $attributes->class('rt-ops-panel__header') }}>
    @if($icon)
        <span class="rt-ops-panel__icon" aria-hidden="true"><i class="far {{ $icon }}"></i></span>
    @endif
    <div class="rt-ops-panel__heading">
        @if(filled($eyebrow))<span class="rt-ops-panel__eyebrow">{{ $eyebrow }}</span>@endif
        <h2 class="rt-ops-panel__title">{{ $title }}</h2>
        @if(filled($subtitle))<p class="rt-ops-panel__subtitle">{{ $subtitle }}</p>@endif
    </div>
    <div class="rt-ops-panel__actions">
        {{ $actions ?? '' }}
        <button type="button" class="rt-ops-panel__icon-button"
            @if($closeAction) wire:click="{{ $closeAction }}" wire:loading.attr="disabled" @else x-on:click="$dispatch('close')" @endif
            aria-label="{{ $closeLabel }}" title="{{ $closeLabel }}" data-dialog-close>
            <i class="far fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
    @isset($meta)
        <div class="rt-ops-panel__meta">{{ $meta }}</div>
    @endisset
</header>
