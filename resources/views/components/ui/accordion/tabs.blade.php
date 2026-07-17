@props([
    // ['anwesenheit' => 'Anwesenheit'] ODER ['anwesenheit' => ['label'=>'…','icon'=>'…']]
    'tabs' => [],
    'default' => null,
    'persistKey' => null,
    // optional: 'sm' | 'md' | 'lg' | 'xl' | '2xl'
    'collapseAt' => null,
])

@php
    use Illuminate\Support\Str;

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
                    $label     = $isArray ? ($tab['label'] ?? Str::title($k)) : $tab;
                    $iconClass = $isArray ? ($tab['icon']  ?? null) : null;
                @endphp
                out.push({ id: '{{ $k }}', label: @js($label), icon: @js($iconClass) });
            @endforeach
            return out;
        })(),
        get active() { return this.items.find(t => t.id === this.openTab) ?? this.items[0]; },
        get others() { return this.items.filter(t => t.id !== this.openTab); },
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
    x-init="setupMQ(@js($collapseAt)); onResize(); $watch('openTab', () => onResize())"
    class="w-full"
    role="tablist"
    wire:key="tutor-course-tabs"
    wire:ignore
>
    <div class="flex -mb-[1px]" x-ref="row" x-resize.debounce.150ms="onResize()">
        <!-- Normalmodus: alle Tabs (Layout unverändert) -->
        <template x-if="!collapsed">
            <template x-for="t in items" :key="t.id">
                <button
                    type="button"
                    @click.prevent="openTab = t.id"
                    :class="openTab === t.id
                        ? 'text-blue-600 font-bold border-blue-300 bg-white border-b-0'
                        : 'text-gray-500 font-medium bg-white border-gray-300 border-b-blue-300'"
                    class="mr-2 px-4 py-2 text-sm transition-all border border-blue-300 border-b-blue-300 rounded-t-lg inline-flex items-center gap-2"
                    role="tab"
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
                    class="mr-2 px-4 py-2 text-sm transition-all border border-blue-300 border-b-blue-300 rounded-t-lg inline-flex items-center gap-2 text-blue-600 font-bold bg-white border-b-0"
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
                        class="px-4 py-2 text-sm transition-all border border-blue-300 border-b-blue-300 rounded-t-lg inline-flex items-center gap-2 text-gray-500 font-medium bg-white border-b-blue-300"
                        :aria-expanded="open" aria-haspopup="menu" title="Weitere Tabs"
                    >
                        <i class="fad fa-bars fa-lg" aria-hidden="true"></i>
                        <span class="whitespace-nowrap">Mehr</span>
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        @click.outside="open=false"
                        class="absolute z-20 mt-1 w-56 rounded-md border border-gray-200 bg-white shadow"
                        role="menu"
                    >
                        <ul class="py-1 max-h-[60vh] overflow-auto">
                            <template x-for="t in others" :key="t.id">
                                <li>
                                    <button
                                        type="button"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 inline-flex items-center gap-2"
                                        role="menuitem"
                                        @click="open=false; openTab = t.id"
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

    <div>
        {{ $slot }}
    </div>
</div>
