@props(['kind' => 'assist'])
<template x-teleport="[data-rt-floating-launcher-group]">
    <div data-rt-floating-launcher-item="{{ $kind === 'communication' ? 'communication' : 'assist' }}"
        {{ $attributes->class(['rt-floating-launcher-item', 'rt-floating-launcher-item--communication' => $kind === 'communication']) }}>
        {{ $slot }}
    </div>
</template>
