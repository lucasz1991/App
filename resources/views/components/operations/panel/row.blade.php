@props(['label'])
<div {{ $attributes->class('rt-ops-panel__row') }}>
    <dt>{{ $label }}</dt>
    <dd>{{ $slot }}</dd>
</div>
