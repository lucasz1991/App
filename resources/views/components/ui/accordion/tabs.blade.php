@props([
    // ['attendance' => 'Attendance'] or ['attendance' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    'persistKey' => null,
    // Kept for backwards compatibility with existing call sites.
    'collapseAt' => 'md',
    'ariaLabel' => null,
    'contentClass' => 'mt-4 sm:mt-6',
])

@php
    $firstKey = array_key_first($tabs);
    $initial = (string) ($default ?? $firstKey ?? 'tab-1');
    $routeName = optional(request()->route())->getName() ?? request()->path();
    $tabsSignature = implode(',', array_keys($tabs));
    $storageKey = $persistKey ?: 'tabs:' . $routeName . $tabsSignature;

    $tabItems = collect($tabs)->map(function ($tab, $id) {
        $isArray = is_array($tab);

        return [
            'id' => (string) $id,
            'label' => $isArray ? ($tab['label'] ?? \Illuminate\Support\Str::title($id)) : $tab,
            'icon' => $isArray ? ($tab['icon'] ?? null) : null,
        ];
    })->values()->all();
@endphp

<div
    x-data="railtimeTabs({
        initial: @js($initial),
        persistedTab: $persist(@js($initial)).as(@js($storageKey)),
        forceDefault: @js((bool) $forceDefault),
        items: @js($tabItems),
    })"
    :data-tab-direction="tabDirection"
    :data-tabs-mobile="mobileTabs ? 'true' : 'false'"
    :data-tabs-scrubbing="scrubbingTabs ? 'true' : 'false'"
    :data-tabs-input-policy="mobileTabs ? 'free-scroll-linked' : 'click-only'"
    class="w-full min-w-0"
    wire:key="{{ \Illuminate\Support\Str::slug($storageKey) }}"
>
    <div
        x-ref="shell"
        class="rt-tabs-shell rt-tabs-v2"
        :data-sticky-enabled="stickyEnabled ? 'true' : 'false'"
        :data-scroll-start="atScrollStart ? 'true' : 'false'"
        :data-scroll-end="atScrollEnd ? 'true' : 'false'"
        role="tablist"
        aria-orientation="horizontal"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent.stop="moveTab(1)"
        @keydown.left.prevent.stop="moveTab(-1)"
        @keydown.home.prevent.stop="moveToBoundary('start')"
        @keydown.end.prevent.stop="moveToBoundary('end')"
        wire:ignore
    >
        <div
            x-ref="carousel"
            class="rt-tabs-carousel"
            data-tab-carousel
            data-slider-library="native-linked-scroll"
            @scroll.passive="onNavigationScroll()"
            @pointerdown="beginPointerDrag($event)"
            @pointermove="movePointerDrag($event)"
            @pointerup="endPointerDrag($event)"
            @pointercancel="endPointerDrag($event)"
            @touchstart.passive="beginTouchScrub()"
            @touchend.passive="endTouchScrub()"
        >
            <div class="rt-tabs-carousel-track">
                <template x-for="tab in items" :key="tab.id">
                    <button
                        type="button"
                        @click="
                            if (suppressClick) return;
                            selectTab(tab.id, true);
                        "
                        :data-active="openTab === tab.id ? 'true' : 'false'"
                        :data-position="openTab === tab.id ? 'active' : 'inactive'"
                        class="rt-carousel-tab group"
                        role="tab"
                        :id="`tab-${tab.id}`"
                        :data-tab-id="tab.id"
                        :aria-controls="`panel-${tab.id}`"
                        :aria-selected="openTab === tab.id"
                        :tabindex="openTab === tab.id ? 0 : -1"
                    >
                        <span
                            class="rt-carousel-tab-icon"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            aria-hidden="true"
                        >
                            <template x-if="tab.icon">
                                <i :class="tab.icon"></i>
                            </template>
                            <template x-if="!tab.icon">
                                <span class="rt-tab-fallback-dot"></span>
                            </template>
                        </span>
                        <span class="rt-carousel-tab-label" x-text="tab.label"></span>
                        <span
                            class="rt-tab-active-mark"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            data-rt-tab-active-mark
                            aria-hidden="true"
                        ></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <div
        x-ref="panels"
        class="rt-tab-panels {{ $contentClass }} relative min-w-0 overflow-hidden"
        data-tab-panels
        :style="panelViewportStyle()"
    >
        <div
            x-ref="panelTrack"
            class="rt-tab-panels-track"
            :style="panelTrackStyle()"
        >
            {{ $slot }}
        </div>
    </div>
</div>
