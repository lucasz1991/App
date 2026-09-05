@props([
    'title',
    'eyebrow' => 'LMZ Page Builder',
    'description' => null,
    'backUrl',
    'backLabel' => 'Zurück zur Übersicht',
    'previewSources' => [],
    'previewDefault' => null,
    'previewReplayable' => false,
    'previewLoadingOverlay' => true,
    'autoOpen' => false,
    // Optionaler harter Seitenwechsel fuer schwere Editoren. Dadurch muss
    // die geschlossene Vorschauseite weder Workspace noch Runtime vorladen.
    'openUrl' => null,
    'renderWorkspace' => true,
    // Opt-in fuer Editoren, deren eigene Werkzeugleiste direkt in den
    // Vollbildkopf gehoert. Der Standardvertrag aller anderen Page Builder
    // bleibt unveraendert zweizeilig.
    'singleToolbar' => false,
    // Explizites Styling-Opt-in; andere Page Builder behalten ihren Kopf.
    'editorMode' => null,
    'workspaceClass' => 'min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-4 sm:px-5',
])

@php
    $shellId = 'page-builder-'.\Illuminate\Support\Str::random(8);
@endphp

<div
    x-data="{
        pageBuilderOpen: @js((bool) $autoOpen),
        pageBuilderTrigger: null,
        init() {
            this.$watch('pageBuilderOpen', (open) => this.sync(open));
            this.$nextTick(() => {
                this.sync(this.pageBuilderOpen);
                if (this.pageBuilderOpen) requestAnimationFrame(() => this.focusPageBuilder());
            });
        },
        sync(open) {
            document.documentElement.classList.toggle('overflow-hidden', Boolean(open));
            this.$nextTick(() => window.dispatchEvent(new CustomEvent(
                open ? 'page-builder-shell:opened' : 'page-builder-shell:closed',
                { detail: { id: @js($shellId) } },
            )));
        },
        openPageBuilder() {
            this.pageBuilderTrigger = document.activeElement;
            this.pageBuilderOpen = true;
            this.$nextTick(() => requestAnimationFrame(() => this.focusPageBuilder()));
        },
        requestClose() {
            const allowed = window.dispatchEvent(new CustomEvent('page-builder-shell:before-close', {
                cancelable: true,
                detail: { id: @js($shellId) },
            }));
            if (allowed) this.approveClose();
        },
        approveClose() {
            const returnTarget = this.pageBuilderTrigger
                || this.$root?.querySelector?.('[data-page-builder-open]');
            this.pageBuilderOpen = false;
            this.$nextTick(() => returnTarget?.focus?.({ preventScroll: true }));
        },
        pageBuilderCompositeRoots() {
            const fullscreen = document.querySelector(
                '[data-page-builder-fullscreen-root][data-page-builder-shell-id=' + CSS.escape(@js($shellId)) + ']'
            )?.closest('[data-page-builder-fullscreen]');
            const assistant = document.querySelector(
                '[data-railtime-chatbot-root][data-pagebuilder-active=true] #railtime-chatbot-panel'
            );
            return [fullscreen, assistant].filter((element) => {
                if (!element) return false;
                const style = window.getComputedStyle(element);
                return style.display !== 'none' && style.visibility !== 'hidden';
            });
        },
        pageBuilderAssistantOpen() {
            const panel = document.querySelector(
                '[data-railtime-chatbot-root][data-pagebuilder-active=true] #railtime-chatbot-panel'
            );
            return document.documentElement.hasAttribute('data-rt-pagebuilder-assist-open')
                 || (panel && window.getComputedStyle(panel).display !== 'none');
        },
        pageBuilderSubdialogOpen() {
            return [...document.querySelectorAll('[data-page-builder-subdialog]')].some((dialog) => {
                const style = window.getComputedStyle(dialog);
                return style.display !== 'none'
                    && style.visibility !== 'hidden'
                    && dialog.getAttribute('aria-hidden') !== 'true';
            });
        },
        pageBuilderFocusables() {
            const selector = [
                'a[href]', 'button:not([disabled])', 'input:not([disabled])',
                'select:not([disabled])', 'textarea:not([disabled])',
                'iframe', '[tabindex]:not([tabindex=&quot;-1&quot;])', '[contenteditable=true]'
            ].join(',');
            return this.pageBuilderCompositeRoots().flatMap((root) => [...root.querySelectorAll(selector)])
                .filter((element) => !element.closest('[inert]')
                    && element.getAttribute('aria-hidden') !== 'true'
                    && element.getClientRects().length > 0
                    && window.getComputedStyle(element).display !== 'none'
                    && window.getComputedStyle(element).visibility !== 'hidden');
        },
        focusPageBuilder() {
            const title = this.pageBuilderCompositeRoots()[0]?.querySelector?.('[data-page-builder-title]');
            (title || this.pageBuilderFocusables()[0])?.focus?.({ preventScroll: true });
        },
        trapPageBuilderFocus(event) {
            if (event.defaultPrevented || this.pageBuilderSubdialogOpen()) return;
            const focusables = this.pageBuilderFocusables();
            if (!focusables.length) return;
            const current = focusables.indexOf(document.activeElement);
            const delta = event.shiftKey ? -1 : 1;
            const next = current < 0
                ? (event.shiftKey ? focusables.length - 1 : 0)
                : (current + delta + focusables.length) % focusables.length;
            event.preventDefault();
            focusables[next]?.focus?.({ preventScroll: true });
        },
        destroy() {
            document.documentElement.classList.remove('overflow-hidden');
        },
    }"
    x-on:keydown.tab.window="if (pageBuilderOpen) trapPageBuilderFocus($event)"
    x-on:page-builder-shell:close-approved.window="if (! $event.detail?.id || $event.detail.id === @js($shellId)) approveClose()"
    data-page-builder-shell
    data-page-builder-preview-first
    data-page-builder-panel-trigger-policy="explicit"
>
    <x-ui.page
        :title="$title"
        :eyebrow="$eyebrow"
        :description="$description"
        :back-url="$backUrl"
        :back-label="$backLabel"
        :auto-intro="false"
        content-class="space-y-4"
        data-page-builder-closed-preview
    >
        <x-slot:actions>
            @if (filled($openUrl))
                <a
                    href="{{ $openUrl }}"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                    data-page-builder-open
                    data-page-builder-load-on-demand
                >
                    <i class="far fa-expand" aria-hidden="true"></i>
                    Vollbildeditor laden
                </a>
            @else
                <x-ui.buttons.button-basic
                    type="button"
                    mode="primary"
                    x-on:click="openPageBuilder()"
                    class="min-h-11 rounded-xl px-3.5"
                    data-page-builder-open
                >
                    <i class="far fa-expand" aria-hidden="true"></i>
                    Vollbildeditor öffnen
                </x-ui.buttons.button-basic>
            @endif
        </x-slot:actions>

        <x-ui.page-builder.preview-card
            :title="$title"
            :description="$description"
            :edit-url="null"
            :sources="$previewSources"
            :default-source="$previewDefault"
            :replayable="$previewReplayable"
            :loading-overlay="$previewLoadingOverlay"
        />
    </x-ui.page>

    @if ($renderWorkspace)
    <x-ui.fullscreen-modal
        state="pageBuilderOpen"
        :trap="false"
        close-action="requestClose()"
        escape-action="if (! pageBuilderAssistantOpen() && ! pageBuilderSubdialogOpen()) requestClose()"
        labelledby="{{ $shellId }}-title"
        body-class="min-h-0 flex-1 overflow-hidden p-0"
        content-class="h-full min-h-0 w-full max-w-none"
        :header-class="$singleToolbar ? 'rt-page-builder-single-header' : ''"
        :class="$editorMode === 'mail' && $singleToolbar ? 'rt-mail-editor-fullscreen' : '[&_header_button]:min-h-11 [&_header_button]:min-w-11'"
        data-page-builder-fullscreen
        data-page-builder-single-toolbar="{{ $singleToolbar ? 'true' : 'false' }}"
        data-page-builder-editor-mode="{{ $editorMode }}"
    >
        <x-slot:header>
            @if ($singleToolbar && isset($toolbar))
                <h2 id="{{ $shellId }}-title" tabindex="-1" class="sr-only outline-none" data-page-builder-title>{{ $title }}</h2>
                <div class="min-w-0 flex-1" data-page-builder-shell-toolbar data-page-builder-single-toolbar-content>
                    {{ $toolbar }}
                </div>
            @else
                <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent sm:flex">
                    <i class="far fa-object-group" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ $eyebrow }}</p>
                    <h2 id="{{ $shellId }}-title" tabindex="-1" class="truncate text-base font-semibold text-rt-text outline-none dark:text-rt-dark-text sm:text-lg" data-page-builder-title>{{ $title }}</h2>
                </div>
            @endif
        </x-slot:header>

        <x-slot:actions>
            @isset($actions)
                {{ $actions }}
            @endisset

            <x-ui.buttons.button-basic
                type="button"
                mode="secondary"
                size="sm"
                class="h-11 min-h-11 rounded-lg px-3"
                x-on:click="$dispatch('railtime-assistant-open', { source: 'pagebuilder' })"
                data-page-builder-assist
                aria-label="KI-Assistent für den Page Builder öffnen"
                title="KI-Assistent öffnen"
            >
                <i class="far fa-comment-dots" aria-hidden="true"></i>
                <span class="hidden xl:inline">KI-Assistent</span>
            </x-ui.buttons.button-basic>
        </x-slot:actions>

        <div
            class="flex h-full min-h-0 flex-col"
            data-page-builder-fullscreen-root
            data-page-builder-shell-id="{{ $shellId }}"
            data-page-builder-fullscreen-content
        >
            @if (isset($toolbar) && ! $singleToolbar)
                <div class="shrink-0 border-b border-rt-border/70 bg-rt-surface px-3 py-2 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-5" data-page-builder-shell-toolbar>
                    {{ $toolbar }}
                </div>
            @endif

            <div
                {{ $attributes->class($workspaceClass) }}
                x-bind:data-page-builder-editor-active="pageBuilderOpen ? 'true' : 'false'"
                data-page-builder-workspace
                data-no-sidebar-swipe
            >
                {{ $slot }}
            </div>
        </div>
    </x-ui.fullscreen-modal>
    @endif
</div>
