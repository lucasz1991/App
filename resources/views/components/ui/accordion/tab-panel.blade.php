@props([
    'for' => null,
    'panelClass' => 'space-y-6',
])

<div
    x-show="openTab === '{{ $for }}'"
    x-cloak
    x-transition:enter="rt-tab-panel-transition"
    x-transition:enter-start="rt-tab-panel-enter-start"
    x-transition:enter-end="rt-tab-panel-enter-end"
    x-transition:leave="rt-tab-panel-transition rt-tab-panel-leave"
    x-transition:leave-start="rt-tab-panel-leave-start"
    x-transition:leave-end="rt-tab-panel-leave-end"
    role="tabpanel"
    id="panel-{{ $for }}"
    aria-labelledby="tab-{{ $for }}"
    :aria-hidden="openTab !== '{{ $for }}'"
    class="rt-tab-panel {{ $panelClass }}"
>
    {{ $slot }}
</div>
