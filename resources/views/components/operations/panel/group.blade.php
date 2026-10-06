@props([
    'title' => null,
    'meta' => null,
    'tone' => null,
])
<section {{ $attributes->class('rt-ops-panel__group') }} @if($tone) data-tone="{{ $tone }}" @endif>
    @if(filled($title) || isset($actions))
        <div class="rt-ops-panel__group-head">
            @if(filled($title))<h3 class="rt-ops-panel__group-title">{{ $title }}</h3>@endif
            @if(filled($meta))<span class="rt-ops-panel__group-meta">{{ $meta }}</span>@endif
            @isset($actions)<div class="rt-ops-panel__group-actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
