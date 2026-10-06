@props([
    'name',
    'idPrefix',
    'model' => 'tab',
])
<section {{ $attributes->class('rt-ops-panel__section') }} role="tabpanel" tabindex="0"
    id="{{ $idPrefix }}-panel-{{ $name }}" aria-labelledby="{{ $idPrefix }}-tab-{{ $name }}"
    x-show="{{ $model }} === @js((string) $name)" data-panel-section="{{ $name }}">
    {{ $slot }}
</section>
