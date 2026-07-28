@props([
    'for' => null,
    'panelClass' => 'space-y-6',
])

<div
    x-show="mobileTabs || openTab === @js((string) $for)"
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
    :aria-hidden="openTab !== @js((string) $for)"
    :inert="openTab !== @js((string) $for)"
    :data-active="openTab === @js((string) $for) ? 'true' : 'false'"
    :data-loaded="isTabLoaded(@js((string) $for)) ? 'true' : 'false'"
    class="rt-tab-panel {{ $panelClass }}"
>
    <div
        x-show="isTabLoaded(@js((string) $for))"
        data-rt-tab-content
        class="rt-tab-panel-content"
    >
        {{ $slot }}
    </div>

    <div
        x-show="!isTabLoaded(@js((string) $for))"
        class="rt-tab-panel-skeleton"
        role="status"
        aria-label="{{ __('app.loading') }}"
    >
        <span class="rt-tab-skeleton-heading"></span>
        <span class="rt-tab-skeleton-line rt-tab-skeleton-line--wide"></span>
        <span class="rt-tab-skeleton-line"></span>
        <span class="rt-tab-skeleton-card"></span>
    </div>
</div>
