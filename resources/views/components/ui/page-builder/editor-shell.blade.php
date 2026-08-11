@props([
    'title',
    'eyebrow' => 'LMZ Page Builder',
    'description' => null,
    'backUrl',
    'backLabel' => 'Zurück zur Übersicht',
    'previewSources' => [],
    'previewDefault' => null,
    'workspaceClass' => 'h-full min-h-0 overflow-y-auto overscroll-contain px-3 py-4 sm:px-5',
])

@php
    $shellId = 'page-builder-'.\Illuminate\Support\Str::random(8);
    $panelTargets = [
        'content' => ['label' => 'Inhalt', 'slot' => 'contentPanel'],
        'properties' => ['label' => 'Eigenschaften', 'slot' => 'propertiesPanel'],
        'styles' => ['label' => 'Stile', 'slot' => 'stylesPanel'],
        'spacing' => ['label' => 'Abstände', 'slot' => 'spacingPanel'],
        'media' => ['label' => 'Medien', 'slot' => 'mediaPanel'],
        'animation' => ['label' => 'Animation', 'slot' => 'animationPanel'],
    ];
@endphp

<div
    x-data="{
        pageBuilderOpen: true,
        init() {
            this.$watch('pageBuilderOpen', (open) => this.sync(open));
            this.$nextTick(() => this.sync(true));
        },
        sync(open) {
            document.documentElement.classList.toggle('overflow-hidden', Boolean(open));
            this.$nextTick(() => window.dispatchEvent(new CustomEvent(
                open ? 'page-builder-shell:opened' : 'page-builder-shell:closed',
                { detail: { id: @js($shellId) } },
            )));
        },
        openPageBuilder() {
            this.pageBuilderOpen = true;
        },
        requestClose() {
            const allowed = window.dispatchEvent(new CustomEvent('page-builder-shell:before-close', {
                cancelable: true,
                detail: { id: @js($shellId) },
            }));
            if (allowed) this.approveClose();
        },
        approveClose() {
            this.pageBuilderOpen = false;
        },
        destroy() {
            document.documentElement.classList.remove('overflow-hidden');
        },
    }"
    x-on:page-builder-shell:close-approved.window="if (! $event.detail?.id || $event.detail.id === @js($shellId)) approveClose()"
    data-page-builder-shell
    data-page-builder-panel-trigger-policy="explicit"
>
    <x-ui.page
        :title="$title"
        :eyebrow="$eyebrow"
        :description="$description"
        :back-url="$backUrl"
        :auto-intro="false"
        content-class="space-y-4"
        data-page-builder-closed-preview
    >
        <x-slot:actions>
            <button type="button" x-on:click="openPageBuilder()" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-accent px-3.5 text-sm font-semibold text-white transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30">
                <i class="far fa-expand" aria-hidden="true"></i>
                Vollbildeditor öffnen
            </button>
        </x-slot:actions>

        <x-ui.page-builder.preview-card
            :title="$title"
            :description="$description"
            :edit-url="null"
            :sources="$previewSources"
            :default-source="$previewDefault"
        />

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" x-on:click="openPageBuilder()" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-accent px-4 text-sm font-semibold text-white transition hover:brightness-95">
                <i class="far fa-expand" aria-hidden="true"></i>
                Vollbildeditor öffnen
            </button>
            <a href="{{ $backUrl }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-4 text-sm font-semibold text-rt-text transition hover:border-rt-accent/40 hover:text-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text">
                <i class="far fa-arrow-left" aria-hidden="true"></i>
                {{ $backLabel }}
            </a>
        </div>
    </x-ui.page>

    <x-ui.fullscreen-modal
        state="pageBuilderOpen"
        :trap="false"
        close-action="requestClose()"
        escape-action="requestClose()"
        labelledby="{{ $shellId }}-title"
        body-class="min-h-0 flex-1 overflow-hidden p-0"
        content-class="h-full min-h-0 w-full max-w-none"
        class="[&_header_button]:min-h-11 [&_header_button]:min-w-11"
        data-page-builder-fullscreen
    >
        <x-slot:header>
            <button type="button" x-on:click="requestClose()" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted transition hover:border-rt-accent/40 hover:text-rt-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent" aria-label="Vollbildeditor schließen">
                <i class="far fa-arrow-left" aria-hidden="true"></i>
            </button>
            <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent sm:flex">
                <i class="far fa-object-group" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ $eyebrow }}</p>
                <h2 id="{{ $shellId }}-title" class="truncate text-base font-semibold text-rt-text dark:text-rt-dark-text sm:text-lg">{{ $title }}</h2>
            </div>
        </x-slot:header>

        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset

        <div class="flex h-full min-h-0 flex-col" data-page-builder-fullscreen-root data-page-builder-shell-id="{{ $shellId }}">
            @isset($toolbar)
                <div class="shrink-0 border-b border-rt-border/70 bg-rt-surface px-3 py-2 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-5" data-page-builder-shell-toolbar>
                    {{ $toolbar }}
                </div>
            @endisset

            <div
                {{ $attributes->class($workspaceClass) }}
                x-bind:data-page-builder-editor-active="pageBuilderOpen ? 'true' : 'false'"
                data-page-builder-workspace
                data-no-sidebar-swipe
            >
                {{ $slot }}
            </div>

            <div class="contents" data-page-builder-panel-host data-page-builder-panel-trigger="explicit">
                @foreach ($panelTargets as $target => $definition)
                    @php($panelSlot = $definition['slot'])
                    <aside
                        hidden
                        aria-hidden="true"
                        data-page-builder-panel-target="{{ $target }}"
                        data-page-builder-railtime-drawer
                        data-page-builder-panel-label="{{ $definition['label'] }}"
                    >
                        @if (isset(${$panelSlot}))
                            {{ ${$panelSlot} }}
                        @endif
                    </aside>
                @endforeach
            </div>
        </div>
    </x-ui.fullscreen-modal>
</div>
