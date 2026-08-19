@props([
    'title',
    'description' => null,
    'editUrl' => null,
    'editLabel' => 'Im Vollbild bearbeiten',
    'sources' => [],
    'defaultSource' => null,
    'status' => null,
    'replayable' => false,
    'loadingOverlay' => true,
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
    // Blade-Direktiven innerhalb der Attribute einer verschachtelten
    // Komponente werden nicht erneut kompiliert. Ein `@js(...)` an
    // <x-ui.preview.frame> wuerde deshalb wortwoertlich im Browser landen und
    // Alpine mit "illegal character U+0040" abbrechen. Nur bereits fertige,
    // datenfreie Alpine-Ausdruecke an die Unterkomponente weiterreichen.
    $frameLoadExpression = $loadingOverlay ? 'frameLoaded($event)' : 'void 0';
    $frameClassExpression = $replayable && $loadingOverlay
        ? "!frameReady ? 'opacity-0' : 'opacity-100'"
        : "'opacity-100'";
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
            titlePrefix: @js('Vorschau: '.$title.' – '),
            scale: 1,
            resizeObserver: null,
            playbackId: 1,
            frameReady: @js(! $replayable || ! $loadingOverlay),
            reducedMotion: false,
            readyTimer: null,
            motionMedia: null,
            motionListener: null,
            get active() {
                return this.sources.find((source) => source.key === this.activeKey) || this.sources[0] || null;
            },
            get activeUrl() {
                if (!this.active?.url) return 'about:blank';
                if (!@js((bool) $replayable)) return this.active.url;
                const url = new URL(this.active.url, window.location.href);
                if (this.reducedMotion) {
                    url.searchParams.delete('animate');
                    url.searchParams.delete('play');
                    url.searchParams.set('static', '1');
                } else {
                    url.searchParams.delete('static');
                    url.searchParams.set('animate', '1');
                    url.searchParams.set('play', String(this.playbackId));
                }
                return url.href;
            },
            choose(key) {
                this.activeKey = key;
                if (@js((bool) $replayable)) {
                    if (@js((bool) $loadingOverlay)) {
                        window.clearTimeout(this.readyTimer);
                        this.frameReady = false;
                    }
                    if (!this.reducedMotion) this.playbackId++;
                }
                this.$nextTick(() => this.measure());
            },
            replay() {
                if (!@js((bool) $replayable) || this.reducedMotion) return;
                if (@js((bool) $loadingOverlay)) {
                    window.clearTimeout(this.readyTimer);
                    this.frameReady = false;
                }
                this.playbackId++;
            },
            frameLoaded(event) {
                if (!@js((bool) ($replayable && $loadingOverlay))) return;
                const frame = event?.currentTarget;
                const loadedSrc = frame?.getAttribute?.('src') || '';
                if (!loadedSrc || loadedSrc === 'about:blank') return;
                window.clearTimeout(this.readyTimer);
                this.readyTimer = window.setTimeout(() => {
                    if (frame?.getAttribute?.('src') !== loadedSrc) return;
                    this.frameReady = true;
                }, 120);
            },
            measure() {
                if (! this.$refs.viewport || ! this.active) return;
                const availableWidth = Math.max(1, this.$refs.viewport.clientWidth - 24);
                const availableHeight = Math.max(1, this.$refs.viewport.clientHeight - 24);
                this.scale = Math.min(availableWidth / this.active.width, availableHeight / this.active.height, 1);
            },
            init() {
                this.motionMedia = window.matchMedia('(prefers-reduced-motion: reduce)');
                this.motionListener = () => {
                    const changed = this.reducedMotion !== this.motionMedia.matches;
                    this.reducedMotion = this.motionMedia.matches;
                    if (changed && @js((bool) ($replayable && $loadingOverlay))) {
                        window.clearTimeout(this.readyTimer);
                        this.frameReady = false;
                    }
                };
                this.motionListener();
                this.motionMedia.addEventListener?.('change', this.motionListener);
                if (typeof ResizeObserver === 'function') {
                    this.resizeObserver = new ResizeObserver(() => this.measure());
                }
                this.$nextTick(() => {
                    this.resizeObserver?.observe(this.$refs.viewport);
                    this.measure();
                });
            },
            destroy() {
                window.clearTimeout(this.readyTimer);
                this.motionMedia?.removeEventListener?.('change', this.motionListener);
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

            @if (count($previewSources) > 1 || $replayable)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if (count($previewSources) > 1)
                    <div class="flex flex-wrap items-center gap-1 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control" role="group" aria-label="Vorschauformat">
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
                @if ($replayable)
                    <button
                        type="button"
                        x-on:click="replay()"
                        x-bind:disabled="reducedMotion"
                        x-bind:title="reducedMotion ? 'Animationen sind wegen reduzierter Bewegung deaktiviert.' : 'Alle Animationen neu starten'"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-xs font-semibold text-rt-accent ring-1 ring-inset ring-rt-accent/25 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30 disabled:cursor-not-allowed disabled:opacity-45 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                        data-page-builder-preview-replay
                    >
                        <i class="far fa-rotate-right" aria-hidden="true"></i>
                        Neu starten
                    </button>
                @endif
                </div>
            @endif
        </div>

        <div
            x-ref="viewport"
            class="relative aspect-[16/10] min-h-52 overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(148,163,184,0.14),_transparent_62%)] dark:bg-[radial-gradient(circle_at_top,_rgba(71,85,105,0.24),_transparent_62%)]"
            data-page-builder-preview-viewport
        >
            @if ($previewSources !== [])
                @if ($replayable && $loadingOverlay)
                    <div
                        x-show="!frameReady"
                        x-transition.opacity
                        class="pointer-events-none absolute inset-0 z-[1] grid place-items-center bg-rt-surface/90 px-5 text-center text-xs font-medium text-rt-muted backdrop-blur-sm dark:bg-rt-dark-surface/90 dark:text-rt-dark-muted"
                        role="status"
                        aria-live="polite"
                        data-page-builder-preview-loading
                    >
                        <span class="flex items-center gap-2">
                            <i class="far fa-spinner-third animate-spin text-rt-accent" aria-hidden="true"></i>
                            Animierte Vorschau wird vorbereitet …
                        </span>
                    </div>
                @endif
                <x-ui.preview.frame
                    :src="$replayable ? 'about:blank' : $initialSource['url']"
                    x-bind:src="activeUrl"
                    x-on:load="{{ $frameLoadExpression }}"
                    :title="'Vorschau: '.$title.' – '.$initialSource['label']"
                    x-bind:title="titlePrefix + (active?.label || 'Vorschau')"
                    class="pointer-events-none absolute left-1/2 top-1/2 max-w-none origin-center shadow-xl transition-opacity duration-200 motion-reduce:transition-none"
                    x-bind:class="{{ $frameClassExpression }}"
                    x-bind:style="active ? `width:${active.width}px;height:${active.height}px;transform:translate(-50%,-50%) scale(${scale})` : ''"
                    style="width: {{ $initialSource['width'] }}px; height: {{ $initialSource['height'] }}px; transform: translate(-50%, -50%) scale(.2);"
                    data-page-builder-preview-frame
                />
            @else
                <div class="absolute inset-0 grid place-items-center px-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">
                    Noch keine Vorschau verfügbar.
                </div>
            @endif

            @if ($loadingOverlay && (filled($editUrl) || filled($initialSource['editUrl'] ?? null)))
                <template x-if="@js(! $replayable) || !frameReady">
                    <a
                        href="{{ $initialSource['editUrl'] ?? $editUrl }}"
                        x-bind:href="active?.editUrl || @js($editUrl)"
                        wire:navigate
                        class="absolute inset-0 z-10 rounded-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent"
                        aria-label="{{ $editLabel }}: {{ $title }}"
                        data-page-builder-preview-edit-link
                    ></a>
                </template>
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
