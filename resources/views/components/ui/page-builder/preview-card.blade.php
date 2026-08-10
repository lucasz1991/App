@props([
    'title',
    'description' => null,
    'editUrl' => null,
    'editLabel' => 'Im Vollbild bearbeiten',
    'sources' => [],
    'defaultSource' => null,
    'status' => null,
])

@php
    $previewSources = collect($sources)
        ->mapWithKeys(function (array $source, string|int $key) use ($editUrl): array {
            $sourceKey = (string) ($source['key'] ?? $key);

            return [$sourceKey => [
                'key' => $sourceKey,
                'label' => (string) ($source['label'] ?? ucfirst($sourceKey)),
                'url' => (string) ($source['url'] ?? ''),
                'width' => max(1, min(4000, (int) ($source['width'] ?? 1200))),
                'height' => max(1, min(4000, (int) ($source['height'] ?? 800))),
                'editUrl' => (string) ($source['editUrl'] ?? $editUrl ?? ''),
            ]];
        })
        ->filter(fn (array $source): bool => filled($source['url']))
        ->values()
        ->all();

    $availableKeys = array_column($previewSources, 'key');
    $initialKey = in_array((string) $defaultSource, $availableKeys, true)
        ? (string) $defaultSource
        : ($availableKeys[0] ?? '');
    $initialSource = collect($previewSources)->firstWhere('key', $initialKey) ?? [
        'label' => 'Vorschau',
        'url' => 'about:blank',
        'width' => 1200,
        'height' => 800,
    ];
@endphp

<x-ui.surface.card
    padding="p-0"
    {{ $attributes->class('overflow-hidden') }}
    data-page-builder-preview-card
>
    <div
        class="flex h-full min-h-0 flex-col"
        x-data="{
            sources: @js($previewSources),
            activeKey: @js($initialKey),
            scale: 1,
            resizeObserver: null,
            get active() {
                return this.sources.find((source) => source.key === this.activeKey) || this.sources[0] || null;
            },
            choose(key) {
                this.activeKey = key;
                this.$nextTick(() => this.measure());
            },
            measure() {
                if (! this.$refs.viewport || ! this.active) return;
                const availableWidth = Math.max(1, this.$refs.viewport.clientWidth - 24);
                const availableHeight = Math.max(1, this.$refs.viewport.clientHeight - 24);
                this.scale = Math.min(availableWidth / this.active.width, availableHeight / this.active.height, 1);
            },
            init() {
                if (typeof ResizeObserver === 'function') {
                    this.resizeObserver = new ResizeObserver(() => this.measure());
                }
                this.$nextTick(() => {
                    this.resizeObserver?.observe(this.$refs.viewport);
                    this.measure();
                });
            },
            destroy() {
                this.resizeObserver?.disconnect();
            },
        }"
    >
        <div class="flex flex-col gap-3 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text sm:text-base">{{ $title }}</h3>
                    @if (filled($status))
                        <span class="inline-flex min-h-6 items-center rounded-full bg-rt-surface-muted px-2 text-[10px] font-bold uppercase tracking-[0.08em] text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                            {{ $status }}
                        </span>
                    @endif
                </div>
                @if (filled($description))
                    <p class="mt-1 line-clamp-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $description }}</p>
                @endif
            </div>

            @if (count($previewSources) > 1)
                <div class="flex shrink-0 flex-wrap items-center gap-1 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control" role="group" aria-label="Vorschauformat">
                    @foreach ($previewSources as $source)
                        <button
                            type="button"
                            x-on:click="choose(@js($source['key']))"
                            x-bind:aria-pressed="activeKey === @js($source['key'])"
                            data-page-builder-preview-source="{{ $source['key'] }}"
                            data-page-builder-preview-url="{{ $source['url'] }}"
                            @class([
                                'min-h-11 rounded-lg px-2.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30',
                            ])
                            x-bind:class="activeKey === @js($source['key'])
                                ? 'bg-rt-surface text-rt-accent shadow-sm dark:bg-rt-dark-surface dark:text-rt-dark-accent'
                                : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                        >{{ $source['label'] }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <div
            x-ref="viewport"
            class="relative aspect-[16/10] min-h-52 overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(148,163,184,0.14),_transparent_62%)] dark:bg-[radial-gradient(circle_at_top,_rgba(71,85,105,0.24),_transparent_62%)]"
            data-page-builder-preview-viewport
        >
            @if ($previewSources !== [])
                <iframe
                    src="{{ $initialSource['url'] }}"
                    x-bind:src="active?.url || 'about:blank'"
                    title="Vorschau: {{ $title }} – {{ $initialSource['label'] }}"
                    x-bind:title="`Vorschau: {{ addslashes($title) }} – ${active?.label || 'Vorschau'}`"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    loading="lazy"
                    tabindex="-1"
                    class="pointer-events-none absolute left-1/2 top-1/2 max-w-none origin-center border-0 bg-white shadow-xl"
                    x-bind:style="active ? `width:${active.width}px;height:${active.height}px;transform:translate(-50%,-50%) scale(${scale})` : ''"
                    style="width: {{ $initialSource['width'] }}px; height: {{ $initialSource['height'] }}px; transform: translate(-50%, -50%) scale(.2);"
                    data-page-builder-preview-frame
                ></iframe>
            @else
                <div class="absolute inset-0 grid place-items-center px-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">
                    Noch keine Vorschau verfügbar.
                </div>
            @endif

            @if (filled($editUrl) || filled($initialSource['editUrl'] ?? null))
                <a
                    href="{{ $initialSource['editUrl'] ?? $editUrl }}"
                    x-bind:href="active?.editUrl || @js($editUrl)"
                    wire:navigate
                    class="absolute inset-0 z-10 rounded-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent"
                    aria-label="{{ $editLabel }}: {{ $title }}"
                    data-page-builder-preview-edit-link
                ></a>
            @endif
        </div>

        @if (filled($editUrl) || filled($initialSource['editUrl'] ?? null))
            <div class="flex items-center justify-end border-t border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70">
                <a href="{{ $initialSource['editUrl'] ?? $editUrl }}" x-bind:href="active?.editUrl || @js($editUrl)" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-accent px-3.5 text-sm font-semibold text-white transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30">
                    <i class="far fa-expand" aria-hidden="true"></i>
                    {{ $editLabel }}
                </a>
            </div>
        @endif
    </div>
</x-ui.surface.card>
