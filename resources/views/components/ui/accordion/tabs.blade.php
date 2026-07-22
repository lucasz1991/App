@props([
    // ['anwesenheit' => 'Anwesenheit'] oder ['anwesenheit' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    'persistKey' => null,
    // optional: 'sm' | 'md' | 'lg' | 'xl' | '2xl'; mobil als sichtbarer Icon-Umschalter
    'collapseAt' => 'md',
    'ariaLabel' => null,
    'contentClass' => 'mt-4 sm:mt-6',
])

@php
    $firstKey = array_key_first($tabs);
    $initial = $default ?? $firstKey ?? 'tab-1';
    $routeName = optional(request()->route())->getName() ?? request()->path();
    $tabsSig = implode(',', array_keys($tabs));
    $key = $persistKey ?: 'tabs:' . $routeName . $tabsSig;
@endphp

<div
    x-data="{
        openTab: $persist('{{ $initial }}').as('{{ $key }}'),
        collapsed: false,
        forceCollapsed: false,
        items: (function() {
            const out = [];
            @foreach($tabs as $k => $tab)
                @php
                    $isArray = is_array($tab);
                    $label = $isArray ? ($tab['label'] ?? \Illuminate\Support\Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon'] ?? null) : null;
                @endphp
                out.push({ id: '{{ $k }}', label: @js($label), icon: @js($iconClass) });
            @endforeach
            return out;
        })(),
        ensureActiveTab() {
            if (!this.items.some(item => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },
        selectTab(id) {
            this.openTab = id;
        },
        moveTab(direction) {
            const index = this.items.findIndex(item => item.id === this.openTab);
            const nextIndex = (index + direction + this.items.length) % this.items.length;
            this.openTab = this.items[nextIndex].id;
            this.$nextTick(() => this.$root.querySelector(`[data-tab-id='${this.openTab}']`)?.focus());
        },
        mq: null,
        setupMQ(breakpoint) {
            if (!breakpoint) return;

            const breakpoints = { sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536 };
            const width = breakpoints[breakpoint];
            if (!width) return;

            this.mq = window.matchMedia(`(min-width: ${width}px)`);
            const update = () => {
                this.forceCollapsed = !this.mq.matches;
                this.onResize();
            };

            this.mq.addEventListener?.('change', update);
            update();
        },
        onResize() {
            this.collapsed = this.forceCollapsed;
        }
    }"
    x-init="if (@js($forceDefault)) { openTab = @js($initial); } ensureActiveTab(); setupMQ(@js($collapseAt)); onResize()"
    class="w-full min-w-0"
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        class="rt-tabs-shell rounded-xl bg-rt-surface p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
        role="tablist"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent="moveTab(1)"
        @keydown.left.prevent="moveTab(-1)"
        @keydown.home.prevent="selectTab(items[0].id)"
        @keydown.end.prevent="selectTab(items[items.length - 1].id)"
        wire:ignore
    >
        <div class="min-w-0" x-resize.debounce.150ms="onResize()">
            <template x-if="!collapsed">
                <div class="flex min-w-0 gap-1">
                    <template x-for="tab in items" :key="tab.id">
                        <button
                            type="button"
                            @click.prevent="selectTab(tab.id)"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            class="rt-desktop-tab inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-all duration-300 ease-rt-spring"
                            role="tab"
                            :id="`tab-${tab.id}`"
                            :data-tab-id="tab.id"
                            :aria-controls="`panel-${tab.id}`"
                            :aria-selected="openTab === tab.id"
                            :tabindex="openTab === tab.id ? 0 : -1"
                        >
                            <template x-if="tab.icon">
                                <i :class="tab.icon + ' fa-lg'" aria-hidden="true"></i>
                            </template>
                            <span class="whitespace-nowrap" x-text="tab.label"></span>
                        </button>
                    </template>
                </div>
            </template>

            <template x-if="collapsed">
                <div class="grid min-w-0 grid-cols-2 gap-1.5">
                    <template x-for="(tab, index) in items" :key="tab.id">
                        <button
                            type="button"
                            @click.prevent="selectTab(tab.id)"
                            :class="[
                                items.length % 2 === 1 && index === items.length - 1 ? 'col-span-2' : ''
                            ]"
                            :data-active="openTab === tab.id ? 'true' : 'false'"
                            class="rt-mobile-tab group relative flex min-h-14 min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2.5 pr-7 text-left text-[13px] font-semibold leading-tight transition-all duration-200 ease-rt-spring active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-sky-400/50 sm:text-sm"
                            role="tab"
                            :id="`tab-${tab.id}`"
                            :data-tab-id="tab.id"
                            :aria-controls="`panel-${tab.id}`"
                            :aria-selected="openTab === tab.id"
                            :tabindex="openTab === tab.id ? 0 : -1"
                        >
                            <span
                                class="rt-mobile-tab-icon flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors"
                                :data-active="openTab === tab.id ? 'true' : 'false'"
                            >
                                <template x-if="tab.icon">
                                    <i :class="tab.icon" aria-hidden="true"></i>
                                </template>
                            </span>
                            <span class="min-w-0 flex-1 break-words" x-text="tab.label"></span>
                            <i x-show="openTab === tab.id" class="rt-mobile-tab-check far fa-check-circle absolute right-2 top-2 text-[11px]" aria-hidden="true"></i>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <div class="{{ $contentClass }} min-w-0">
        {{ $slot }}
    </div>
</div>
