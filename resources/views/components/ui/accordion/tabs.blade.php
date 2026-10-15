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
    // Optional migration map for renamed/merged persisted tab IDs.
    'persistAliases' => [],
    // Kept for backwards compatibility with existing call sites.
    'collapseAt' => 'md',
    'ariaLabel' => null,
    'contentClass' => 'mt-4 sm:mt-6',
    // The existing pill rail stays the default for all current callers.
    'appearance' => 'pill',
    'allTabs' => false,
])

@php
    $firstKey = array_key_first($tabs);
    $initial = (string) ($default ?? $firstKey ?? 'tab-1');
    $routeName = optional(request()->route())->getName() ?? request()->path();
    $tabsSignature = implode(',', array_keys($tabs));
    $storageKey = $persistKey ?: 'tabs:' . $routeName . $tabsSignature;
    $underline = $appearance === 'underline';
    $showAllTabs = filter_var($allTabs, FILTER_VALIDATE_BOOLEAN) && count($tabs) > 0;

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
        persistAliases: @js($persistAliases),
        items: @js($tabItems),
    })"
    x-effect="if (@js((bool) $resetOnOpen) && typeof show !== 'undefined') syncModalOpenState(show)"
    :data-tab-direction="tabDirection"
    :data-tabs-mobile="mobileTabs ? 'true' : 'false'"
    :data-tabs-scrubbing="scrubbingTabs ? 'true' : 'false'"
    :data-tabs-input-policy="mobileTabs ? 'free-scroll-linked' : 'click-only'"
    :data-tabs-ready="tabsReady ? 'true' : 'false'"
    {{ $attributes->class('w-full min-w-0')->merge([
        'data-anim' => 'fade-up',
        'data-anim-delay' => '0.06',
    ]) }}
    wire:key="{{ \Illuminate\Support\Str::slug($storageKey) }}"
    wire:ignore.self
    data-rt-tabs-root
    data-no-sidebar-swipe
>
    {{-- Die Navigation ist vollstaendig Alpine-gesteuert und statisch.
         x-ignore darf hier bewusst NICHT verwendet werden: Es wuerde x-data,
         x-show und die Alpine-Templates deaktivieren. --}}
    <div
        x-ref="shell"
        class="rt-tabs-shell rt-tabs-v2{{ $underline ? ' rt-tabs-underline' : '' }}{{ $showAllTabs ? ' rt-tabs-with-picker' : '' }}"
        :data-sticky-enabled="stickyEnabled ? 'true' : 'false'"
        :data-scroll-start="atScrollStart ? 'true' : 'false'"
        :data-scroll-end="atScrollEnd ? 'true' : 'false'"
        @unless($showAllTabs)
        role="tablist"
        aria-orientation="horizontal"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent.stop="moveTab(1)"
        @keydown.left.prevent.stop="moveTab(-1)"
        @keydown.home.prevent.stop="moveToBoundary('start')"
        @keydown.end.prevent.stop="moveToBoundary('end')"
        @endunless
        wire:ignore
    >
        <div
            x-ref="carousel"
            class="rt-tabs-carousel"
            data-tab-carousel
            data-slider-library="coupled-transform-rail"
            @if($showAllTabs)
            role="tablist"
            aria-orientation="horizontal"
            aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
            @keydown.right.prevent.stop="moveTab(1)"
            @keydown.left.prevent.stop="moveTab(-1)"
            @keydown.home.prevent.stop="moveToBoundary('start')"
            @keydown.end.prevent.stop="moveToBoundary('end')"
            @endif
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
                {{-- Ein gemeinsamer Indikator fuer alle Tabs: die Pill gleitet mit
                     der Bruchposition, der Aktivpunkt darunter dehnt sich unterwegs
                     (renderIndicator in tabs.js). --}}
                <span x-ref="pill" class="rt-tab-pill" aria-hidden="true"></span>
                <span
                    x-ref="dot"
                    class="rt-tab-active-mark"
                    data-rt-tab-active-mark
                    aria-hidden="true"
                ><i></i></span>
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
                    </button>
                </template>
            </div>
        </div>

        @if($showAllTabs)
            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="80"
                :max-height="480"
                :trap="true"
                :content-label="$ariaLabel ?: __('app.select_section')"
                content-classes="rt-tabs-picker-panel p-2"
                class="rt-tabs-picker"
                x-init="$watch('open', value => { if (value) $nextTick(() => { syncAnchoredPanel($refs.panel); focusTabPicker($refs.panel) }) })"
            >
                <x-slot:trigger>
                    <button
                        type="button"
                        class="rt-tabs-picker-trigger"
                        aria-haspopup="menu"
                        x-bind:aria-expanded="open.toString()"
                        aria-label="{{ __('app.select_section') }} ({{ count($tabItems) }})"
                        @keydown.arrow-down.prevent.stop="openDropdown(true)"
                        @keydown.arrow-up.prevent.stop="openDropdown(true)"
                    >
                        <span>{{ app()->getLocale() === 'de' ? 'Alle' : 'All' }} {{ count($tabItems) }}</span>
                        <i class="far fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </x-slot:trigger>
                <x-slot:content>
                    <div class="rt-tabs-picker-label">{{ $ariaLabel ?: __('app.select_section') }}</div>
                    <div data-tab-picker-list @keydown="moveTabPickerFocus($event)">
                        <template x-for="tab in items" :key="tab.id">
                            <button
                                type="button"
                                role="menuitemradio"
                                class="rt-tabs-picker-option"
                                data-tab-choice
                                :data-tab-choice-id="tab.id"
                                :aria-checked="openTab === tab.id"
                                :tabindex="openTab === tab.id ? 0 : -1"
                                @click="selectTab(tab.id); close(true)"
                            >
                                <span class="rt-tabs-picker-icon" aria-hidden="true"><i :class="tab.icon || 'far fa-circle'"></i></span>
                                <span class="rt-tabs-picker-option-label" x-text="tab.label"></span>
                                <i class="far fa-check rt-tabs-picker-check" x-show="openTab === tab.id" aria-hidden="true"></i>
                            </button>
                        </template>
                    </div>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
        @endif
    </div>

    <div
        x-ref="panels"
        class="rt-tab-panels {{ $contentClass }} relative min-w-0 overflow-hidden"
        data-tab-panels
        wire:ignore.self
        :style="panelViewportStyle()"
        @pointerdown="beginCoupledDrag($event, 'content')"
        @pointermove="moveCoupledDrag($event)"
        @pointerup="endCoupledDrag($event)"
        @pointercancel="endCoupledDrag($event)"
        @click.capture="
            if (!suppressClick || isInteractiveGestureTarget($event.target)) return;
            $event.preventDefault();
            $event.stopPropagation();
        "
    >
        <div
            x-ref="panelTrack"
            class="rt-tab-panels-track"
            wire:ignore.self
        >
            {{ $slot }}
        </div>
    </div>
</div>
