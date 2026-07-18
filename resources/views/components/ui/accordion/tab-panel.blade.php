@props([
    'for' => null,
    'panelClass' => 'space-y-6',
])

<div
    x-show="openTab === '{{ $for }}'"
    x-cloak
    x-transition.opacity.duration.150ms
    role="tabpanel"
    id="panel-{{ $for }}"
    aria-labelledby="tab-{{ $for }}"
    :aria-hidden="openTab !== '{{ $for }}'"
    class="{{ $panelClass }}"
>
    {{ $slot }}
</div>
