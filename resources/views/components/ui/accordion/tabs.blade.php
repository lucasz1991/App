@props([
    // ['anwesenheit' => 'Anwesenheit'] ODER ['anwesenheit' => ['label'=>'…','icon'=>'…']]
    'tabs' => [],
    'default' => null,
    'persistKey' => null,
    // optional: 'sm' | 'md' | 'lg' | 'xl' | '2xl'
    'collapseAt' => null,
])

@php
    $firstKey   = array_key_first($tabs);
    $initial    = $default ?? $firstKey ?? 'tab-1';

    $routeName  = optional(request()->route())->getName() ?? request()->path();
    $tabsSig    = implode(',', array_keys($tabs));
    $autoKey    = 'tabs:' . $routeName . $tabsSig;

    $key = $persistKey ?: $autoKey;
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
                    $isArray   = is_array($tab);
                    $label     = $isArray ? ($tab['label'] ?? \Illuminate\Support\Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon']  ?? null) : null;
                @endphp
                out.push({ id: '{{ $k }}', label: @js($label), icon: @js($iconClass) });
            @endforeach
            return out;
        })(),
        get active() { return this.items.find(t => t.id === this.openTab) ?? this.items[0]; },
        get others() { return this.items.filter(t => t.id !== this.openTab); },
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
            this.$nextTick(() => this.$root.querySelector(`[data-tab-id="${this.openTab}"]`)?.focus());
        },
        mq: null,
        setupMQ(bp) {
            if (!bp) return;
            const map = { sm:640, md:768, lg:1024, xl:1280, '2xl':1536 };
            const px  = map[bp];
            if (!px) return;
            this.mq = window.matchMedia(`(min-width: ${px}px)`);
            const update = () => { this.forceCollapsed = !this.mq.matches; };
            this.mq.addEventListener?.('change', update);
            update();
        },
        onResize() {
            if (this.forceCollapsed) { this.collapsed = true; return; }
            // Falls du zusätzlich overflow-basiert kollabieren willst, ent-kommentieren:
            // const row = this.$refs.row; this.collapsed = row ? (row.scrollWidth > row.clientWidth) : false;
            this.collapsed = false;
        }
    }"
    x-init="ensureActiveTab(); setupMQ(@js($collapseAt)); onResize(); $watch('openTab', () => onResize())"
    class="w-full"
    wire:key="{{ \Illuminate\Support\Str::slug($key) }}"
>
    <div
        class="rounded-xl border border-rt-border bg-rt-surface p-1.5 shadow-sm dark:border-rt-dark-border dark:bg-rt-dark-surface"
        role="tablist"
        aria-label="{{ __('app.profile') }}"
        @keydown.right.prevent="moveTab(1)"
        @keydown.left.prevent="moveTab(-1)"
        @keydown.home.prevent="selectTab(items[0].id)"
        @keydown.end.prevent="selectTab(items[items.length - 1].id)"
        wire:ignore
    >
    <div class="flex gap-1" x-ref="row" x-resize.debounce.150ms="onResize()">
        <!-- Normalmodus: alle Tabs (Layout unverändert) -->
        <template x-if="!collapsed">
            <template x-for="t in items" :key="t.id">
                <button
                    type="button"
                    @click.prevent="selectTab(t.id)"
                    :class="openTab === t.id
                        ? 'border-rt-accent/30 bg-rt-accent-soft text-rt-accent shadow-sm dark:border-rt-dark-accent/40 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent'
                        : 'border-transparent text-rt-muted hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text'"
                    class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition"
                    role="tab"
                    :id="`tab-${t.id}`"
                    :data-tab-id="t.id"
                    :aria-controls="`panel-${t.id}`"
                    :aria-selected="openTab === t.id"
                    :tabindex="openTab === t.id ? 0 : -1"
                >
                    <template x-if="t.icon">
                        <i :class="t.icon + ' fa-lg'" aria-hidden="true"></i>
                    </template>
                    <span class="whitespace-nowrap" x-text="t.label"></span>
                </button>
            </template>
        </template>

        <!-- Collapsed: aktiver Tab + Menü (Buttons behalten deine Klassen) -->
        <template x-if="collapsed">
            <div class="contents">
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-rt-accent/30 bg-rt-accent-soft px-4 py-2 text-sm font-semibold text-rt-accent shadow-sm dark:border-rt-dark-accent/40 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"
                    role="tab" aria-selected="true" tabindex="0"
                >
                    <template x-if="active?.icon">
                        <i :class="active.icon + ' fa-lg'" aria-hidden="true"></i>
                    </template>
                    <span class="whitespace-nowrap" x-text="active?.label ?? ''"></span>
                </button>

                <div class="relative" x-data="{ open:false }">
                    <button
                        type="button"
                        @click="open=!open"
                        @keydown.escape.window="open=false"
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-transparent px-4 py-2 text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text"
                        :aria-expanded="open" aria-haspopup="menu" title="Weitere Tabs"
                    >
                        <i class="fad fa-bars fa-lg" aria-hidden="true"></i>
                        <span class="whitespace-nowrap">Mehr</span>
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        @click.outside="open=false"
                        class="absolute z-20 mt-2 w-60 rounded-lg border border-rt-border bg-rt-surface p-1.5 shadow-xl dark:border-rt-dark-border dark:bg-rt-dark-surface"
                        role="menu"
                    >
                        <ul class="py-1 max-h-[60vh] overflow-auto">
                            <template x-for="t in others" :key="t.id">
                                <li>
                                    <button
                                        type="button"
                                        class="inline-flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-rt-text transition hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                                        role="menuitem"
                                        @click="open=false; selectTab(t.id)"
                                    >
                                        <template x-if="t.icon">
                                            <i :class="t.icon + ' fa-lg'" aria-hidden="true"></i>
                                        </template>
                                        <span x-text="t.label"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </template>
    </div>
    </div>

    <div class="mt-6">
        {{ $slot }}
    </div>
</div>
