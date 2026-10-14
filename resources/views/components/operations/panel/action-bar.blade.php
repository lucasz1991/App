@props(['label' => 'Eingang bearbeiten'])
<div {{ $attributes->class('rt-panel-action-bar') }} role="group" aria-label="{{ $label }}">
    @isset($secondary)<div class="rt-panel-action-bar__secondary">{{ $secondary }}</div>@endisset
    <div class="rt-panel-action-bar__primary">{{ $slot }}</div>
</div>
