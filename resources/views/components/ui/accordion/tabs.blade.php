@props([
    // ['attendance' => 'Attendance'] or ['attendance' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    // Bestehende Tabs merken ihre Auswahl weiterhin. Einzelne Formulare und
    // Dialoge koennen die Persistenz explizit mit :persist="false" abschalten.
    'persist' => true,
    'resetOnOpen' => false,
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
        persistedTab: @if ($persist) $persist(@js($initial)).as(@js($storageKey)) @else @js($initial) @endif,
        forceDefault: @js((bool) $forceDefault),
        resetOnOpen: @js((bool) $resetOnOpen),
        items: @js($tabItems),
    })"
    x-effect="if (@js((bool) $resetOnOpen) && typeof show !== 'undefined') syncModalOpenState(show)"
    :data-tab-direction="tabDirection"
    :data-tabs-mobile="mobileTabs ? 'true' : 'false'"
    :data-tabs-scrubbing="scrubbingTabs ? 'true' : 'false'"
    :data-tabs-input-policy="mobileTabs ? 'free-scroll-linked' : 'click-only'"
    {{ $attributes->class('w-full min-w-0')->merge([
        'data-anim' => 'fade-up',
        'data-anim-delay' => '0.06',
    ]) }}
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
            data-slider-library="coupled-transform-rail"
            @pointerdown="beginCoupledDrag($event, 'navigation')"
            @pointermove="moveCoupledDrag($event)"
            @pointerup="endCoupledDrag($event)"
            @pointercancel="endCoupledDrag($event)"
            @wheel="onNavigationWheel($event)"
        >
            <div
                x-ref="carouselTrack"
                class="rt-tabs-carousel-track"
            >
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
        @pointerdown="beginCoupledDrag($event, 'content')"
        @pointermove="moveCoupledDrag($event)"
        @pointerup="endCoupledDrag($event)"
        @pointercancel="endCoupledDrag($event)"
        @click.capture="
            if (!suppressClick) return;
            $event.preventDefault();
            $event.stopPropagation();
        "
    >
        <div
            x-ref="panelTrack"
            class="rt-tab-panels-track"
        >
            {{ $slot }}
        </div>
    </div>
</div>
