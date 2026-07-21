@props([
    // ['anwesenheit' => 'Anwesenheit'] oder ['anwesenheit' => ['label' => '...', 'icon' => '...']]
    'tabs' => [],
    'default' => null,
    'forceDefault' => false,
    'persistKey' => null,
    // optional: 'sm' | 'md' | 'lg' | 'xl' | '2xl'; mobil standardmäßig als Auswahlmenü
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
        menuOpen: false,
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
        get active() {
            return this.items.find(item => item.id === this.openTab) ?? this.items[0];
        },
        ensureActiveTab() {
            if (!this.items.some(item => item.id === this.openTab)) {
                this.openTab = this.items[0]?.id ?? null;
            }
        },
        selectTab(id) {
            this.openTab = id;
            this.menuOpen = false;
        },
        moveTab(direction) {
            const index = this.items.findIndex(item => item.id === this.openTab);
            const nextIndex = (index + direction + this.items.length) % this.items.length;
            this.openTab = this.items[nextIndex].id;
            this.menuOpen = false;
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
            if (!this.collapsed) this.menuOpen = false;
        }
    }"
    x-init="if (@js($forceDefault)) { openTab = @js($initial); } ensureActiveTab(); setupMQ(@js($collapseAt)); onResize()"
    class="w-full min-w-0"
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        class="rounded-xl bg-rt-surface p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
        role="tablist"
        aria-label="{{ $ariaLabel ?: __('app.select_section') }}"
        @keydown.right.prevent="moveTab(1)"
        @keydown.left.prevent="moveTab(-1)"
        @keydown.home.prevent="selectTab(items[0].id)"
        @keydown.end.prevent="selectTab(items[items.length - 1].id)"
        wire:ignore
    >
        <div class="flex min-w-0 gap-1" x-resize.debounce.150ms="onResize()">
            <template x-if="!collapsed">
                <template x-for="tab in items" :key="tab.id">
                    <button
                        type="button"
                        @click.prevent="selectTab(tab.id)"
                        :class="openTab === tab.id
                            ? 'border-rt-accent/30 bg-rt-accent-soft text-rt-accent shadow-rt-xs dark:border-rt-dark-accent/40 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent'
                            : 'border-transparent text-rt-muted hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text'"
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-all duration-300 ease-rt-spring"
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
            </template>

            {{-- Mobil: ein vollbreiter Bereichswähler verhindert horizontalen Overflow. --}}
            <template x-if="collapsed">
                <div
                    class="relative min-w-0 flex-1"
                    @click.outside="menuOpen = false"
                    @keydown.escape.window="menuOpen = false"
                >
                    <button
                        type="button"
                        @click="menuOpen = !menuOpen"
                        class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-lg border border-rt-accent/30 bg-rt-accent-soft px-3.5 py-2.5 text-left text-sm font-semibold text-rt-accent shadow-rt-xs transition-colors duration-200 dark:border-rt-dark-accent/40 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"
                        :aria-expanded="menuOpen"
                        aria-haspopup="listbox"
                    >
                        <template x-if="active?.icon">
                            <i :class="active.icon + ' fa-lg shrink-0'" aria-hidden="true"></i>
                        </template>
                        <span class="min-w-0 flex-1 truncate" x-text="active?.label ?? ''"></span>
                        <span class="hidden text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted min-[360px]:inline">
                            {{ __('app.select_section') }}
                        </span>
                        <i
                            class="fad fa-chevron-down shrink-0 transition-transform duration-200"
                            :class="menuOpen && 'rotate-180'"
                            aria-hidden="true"
                        ></i>
                    </button>

                    <div
                        x-cloak
                        x-show="menuOpen"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="absolute inset-x-0 z-30 mt-2 max-h-[min(22rem,60dvh)] overflow-y-auto rounded-xl bg-rt-surface p-1.5 shadow-rt-md ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                        role="listbox"
                    >
                        <template x-for="tab in items" :key="tab.id">
                            <button
                                type="button"
                                class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors duration-200"
                                :class="openTab === tab.id
                                    ? 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent'
                                    : 'text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted'"
                                role="option"
                                :aria-selected="openTab === tab.id"
                                @click="selectTab(tab.id)"
                            >
                                <template x-if="tab.icon">
                                    <i :class="tab.icon + ' fa-lg shrink-0'" aria-hidden="true"></i>
                                </template>
                                <span class="min-w-0 flex-1 truncate" x-text="tab.label"></span>
                                <i x-show="openTab === tab.id" class="fad fa-check shrink-0" aria-hidden="true"></i>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div class="{{ $contentClass }} min-w-0">
        {{ $slot }}
    </div>
</div>
