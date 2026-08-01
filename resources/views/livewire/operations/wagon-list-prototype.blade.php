<div
    x-data="wagonListPrototype(@js([
        'storageKey' => $storageKey,
        'locale' => app()->getLocale() === 'de' ? 'de-DE' : 'en-GB',
        'cancel' => __('app.cancel'),
        'notSaved' => __('app.wagon_not_saved'),
        'deleteTitle' => $labels['deleteTitle'],
        'deleteText' => $labels['deleteText'],
        'deleteConfirm' => $labels['deleteConfirm'],
        'deleteAllTitle' => $labels['deleteAllTitle'],
        'deleteAllText' => $labels['deleteAllText'],
        'deleteAllConfirm' => $labels['deleteAllConfirm'],
        'draftSaved' => $labels['draftSaved'],
        'saveError' => $labels['saveError'],
        'trainLabel' => $labels['trainLabel'],
        'untitledDraft' => $labels['untitledDraft'],
        'noRoute' => $labels['noRoute'],
        'exportUrl' => auth()->user()->usesAdminLayout()
            ? route('admin.operations.wagon-list.export')
            : route('operations.wagon-list.export'),
        'exportSuccess' => __('app.wagon_export_success'),
        'exportError' => __('app.wagon_export_error'),
        'clearWagonTitle' => __('app.wagon_clear_title'),
        'clearWagonText' => __('app.wagon_clear_text'),
        'clearWagonConfirm' => __('app.clear_wagon'),
        'mobileSteps' => [
            ['id' => 'train', 'label' => __('app.train_data')],
            ['id' => 'identity', 'label' => __('app.identification')],
            ['id' => 'vehicle', 'label' => __('app.axles_dimensions')],
            ['id' => 'brakes', 'label' => __('app.brakes')],
            ['id' => 'route', 'label' => __('app.route_and_notes')],
            ['id' => 'calculation', 'label' => __('app.brake_calculation')],
            ['id' => 'special', 'label' => __('app.special_information')],
            ['id' => 'review', 'label' => __('app.review_and_finish')],
        ],
    ]))"
    class="min-w-0"
    data-wagon-list-prototype
    @keydown.escape.window="handleEscape($event)"
    @keydown.tab.window="trapEditorFocus($event)"
    x-on:railtime-wagon-assistant-command.window="handleAssistantCommand($event.detail)"
>
    @php
        $inputClass = 'rt-ui-control rt-wagon-input mt-1 block min-h-11 w-full rounded-lg border border-rt-border bg-rt-control px-3 py-2 text-base text-rt-text shadow-rt-xs outline-none transition focus:border-rt-accent focus:ring-2 focus:ring-rt-accent/20 sm:text-sm';
        $labelClass = 'text-xs font-semibold text-rt-muted dark:text-rt-dark-muted';
        $sheetInput = 'rt-wagon-sheet-input h-10 w-full min-w-0 border-0 bg-transparent px-2 text-sm tabular-nums text-rt-text outline-none focus:bg-sky-50 focus:ring-2 focus:ring-inset focus:ring-sky-400/55 dark:text-rt-dark-text dark:focus:bg-sky-500/10';
    @endphp

    <x-ui.page
        :title="__('app.wagon_list')"
        :eyebrow="__('app.operational_control')"
        :description="__('app.wagon_list_description')"
    >
        <x-slot:actions>
            <x-ui.dropdown.page-actions>
                <x-dropdown-link
                    x-show.important="drafts.length > 0"
                    x-cloak
                    @click.prevent="deleteAllDrafts()"
                    tone="danger"
                >
                    <i class="far fa-trash-alt" aria-hidden="true"></i>
                    {{ $labels['deleteAll'] }}
                </x-dropdown-link>
                <x-dropdown-link
                    x-ref="newDraftButton"
                    @click.prevent="createDraft($event.currentTarget)"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                    {{ $labels['newDraft'] }}
                </x-dropdown-link>
            </x-ui.dropdown.page-actions>
        </x-slot:actions>

        <section class="space-y-5" aria-labelledby="wagon-drafts-title" data-wagon-draft-overview>
            <div class="rt-wagon-draft-hero relative overflow-hidden rounded-2xl p-5 shadow-rt-sm sm:p-7">
                <div class="relative grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                    <div class="flex min-w-0 items-start gap-4">
                        <span class="rt-wagon-draft-hero-icon flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg sm:h-14 sm:w-14">
                            <i class="fad fa-train" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold tracking-wide text-rt-red dark:text-rt-dark-accent">{{ __('app.locally_saved') }}</p>
                            <h2 id="wagon-drafts-title" class="mt-1 text-balance text-xl font-bold tracking-tight text-rt-text sm:text-2xl dark:text-rt-dark-text">
                                {{ $labels['overviewTitle'] }}
                            </h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $labels['overviewDescription'] }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 rounded-xl border border-white/70 bg-white/75 px-4 py-3 shadow-rt-xs backdrop-blur-sm dark:border-white/10 dark:bg-slate-950/35">
                        <strong class="row-span-2 text-3xl font-bold tabular-nums text-rt-text dark:text-rt-dark-text" x-text="drafts.length"></strong>
                        <span class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">{{ $labels['drafts'] }}</span>
                        <span class="text-[11px] text-rt-soft dark:text-rt-dark-soft">{{ __('app.locally_saved') }}</span>
                    </div>
                </div>
            </div>

            <div
                x-show="drafts.length === 0"
                x-cloak
                class="rt-wagon-empty-state rounded-2xl border border-dashed px-5 py-12 text-center sm:px-8 sm:py-16"
            >
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-accent-soft text-xl text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    <i class="far fa-file-plus" aria-hidden="true"></i>
                </span>
                <h3 class="mt-5 text-lg font-semibold text-rt-text dark:text-rt-dark-text">{{ $labels['emptyTitle'] }}</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $labels['emptyText'] }}</p>
                <button
                    type="button"
                    @click="createDraft($event.currentTarget)"
                    class="mt-6 inline-flex min-h-11 items-center gap-2 rounded-lg bg-rt-red px-5 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition-all duration-200 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red-dark hover:shadow-rt-glow active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-canvas"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                    {{ $labels['newDraft'] }}
                </button>
            </div>

            <div x-show.important="drafts.length > 0" x-cloak class="grid gap-3 lg:grid-cols-2">
                <template x-for="draft in sortedDrafts" :key="draft.id">
                    <article class="rt-wagon-draft-card group flex min-w-0 flex-col rounded-2xl p-4 shadow-rt-xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-rt-sm sm:p-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="rt-wagon-draft-card-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-xl">
                                <i class="far fa-file-spreadsheet" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <button
                                    type="button"
                                    @click="openDraft(draft.id, $event.currentTarget)"
                                    class="block max-w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                                >
                                    <span class="block truncate text-base font-semibold text-rt-text transition group-hover:text-rt-red dark:text-rt-dark-text dark:group-hover:text-rt-dark-accent" x-text="draftTitle(draft)"></span>
                                    <span class="mt-1 block truncate text-sm text-rt-muted dark:text-rt-dark-muted" x-text="draftRoute(draft)"></span>
                                </button>
                            </div>
                            <span class="shrink-0 rounded-md bg-rt-surface-muted px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                {{ $labels['localDraft'] }}
                            </span>
                        </div>

                        <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 border-y border-rt-border/70 py-4 text-xs dark:border-rt-dark-border/70 sm:grid-cols-3">
                            <div class="min-w-0">
                                <dt class="text-rt-soft dark:text-rt-dark-soft">{{ __('app.date') }}</dt>
                                <dd class="mt-1 truncate font-semibold tabular-nums text-rt-text dark:text-rt-dark-text" x-text="formatDate(draft.meta.date)"></dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-rt-soft dark:text-rt-dark-soft">{{ $labels['filledWagons'] }}</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-rt-text dark:text-rt-dark-text">
                                    <span x-text="draftWagonCount(draft)"></span>/<span x-text="draft.visibleCount"></span>
                                </dd>
                            </div>
                            <div class="col-span-2 min-w-0 sm:col-span-1">
                                <dt class="text-rt-soft dark:text-rt-dark-soft">{{ $labels['updatedAt'] }}</dt>
                                <dd class="mt-1 truncate font-semibold tabular-nums text-rt-text dark:text-rt-dark-text" x-text="formatSavedAt(draft.persistedAt)"></dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                            <button
                                type="button"
                                @click="deleteDraft(draft.id)"
                                class="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-rt-muted transition-all duration-200 hover:bg-red-50 hover:text-red-700 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400/45 dark:text-rt-dark-muted dark:hover:bg-red-500/10 dark:hover:text-red-300"
                            >
                                <i class="far fa-trash-alt" aria-hidden="true"></i>
                                {{ $labels['deleteDraft'] }}
                            </button>
                            <button
                                type="button"
                                @click="openDraft(draft.id, $event.currentTarget)"
                                class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rt-text px-4 py-2 text-xs font-semibold text-white transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-700 active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:bg-rt-dark-text dark:text-slate-950 dark:hover:bg-white"
                            >
                                {{ $labels['continueDraft'] }}
                                <i class="far fa-arrow-right" aria-hidden="true"></i>
                            </button>
                        </div>
                    </article>
                </template>
            </div>
        </section>

        <x-ui.fullscreen-modal
            state="editorOpen"
            close-action="cancelEditor()"
            escape-action="cancelEditor()"
            :close-on-escape="false"
            :trap="false"
            labelledby="wagon-editor-title"
            body-class="rt-wagon-editor-body min-h-0 flex-1 overflow-hidden"
            content-class="h-full min-h-0"
            header-class="rt-wagon-editor-header"
            x-ref="editorDialog"
            class="rt-wagon-editor"
            data-wagon-editor
        >
            <x-slot:header>
                <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent sm:flex">
                    <i class="fad fa-train" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="hidden text-[10px] font-bold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft sm:block">{{ $labels['localDraft'] }}</p>
                    <h2 id="wagon-editor-title" x-ref="editorHeading" tabindex="-1" class="truncate text-sm font-semibold outline-none sm:text-lg" x-text="activeDraftId ? draftTitle({ meta }) : @js($labels['untitledDraft'])"></h2>
                    <p class="mt-0.5 truncate text-[10px] text-rt-muted dark:text-rt-dark-muted sm:text-xs">
                        {{ __('app.locally_saved') }} · <span class="tabular-nums" x-text="formatSavedAt()"></span>
                    </p>
                </div>
            </x-slot:header>

            <x-slot:actions>
                <button
                    type="button"
                    @click="resetDraft()"
                    class="rt-wagon-editor-secondary-action h-10 w-10 items-center justify-center rounded-lg border border-transparent text-rt-muted transition-all duration-200 hover:border-red-200 hover:bg-red-50 hover:text-red-700 active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400/45 dark:text-rt-dark-muted dark:hover:border-red-500/25 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                    title="{{ $labels['deleteDraft'] }}"
                    aria-label="{{ $labels['deleteDraft'] }}"
                >
                    <i class="far fa-trash-alt" aria-hidden="true"></i>
                </button>
                <button
                    type="button"
                    @click="exportWorkbook()"
                    :disabled="exporting"
                    class="rt-wagon-editor-secondary-action h-10 items-center justify-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-3 text-sm font-semibold text-rt-text shadow-rt-xs transition-all duration-200 hover:border-rt-accent/35 hover:bg-rt-surface-muted hover:text-rt-accent active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/35 disabled:cursor-wait disabled:opacity-65 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:border-rt-dark-accent/35 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                    title="{{ __('app.export_excel') }}"
                    aria-label="{{ __('app.export_excel') }}"
                >
                    <i class="far fa-file-excel" x-show="!exporting" aria-hidden="true"></i>
                    <i class="far fa-spinner fa-spin" x-show="exporting" x-cloak aria-hidden="true"></i>
                    <span class="rt-wagon-editor-export-label" x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
                </button>
                <button
                    type="button"
                    @click="saveAndClose()"
                    class="rt-wagon-editor-desktop-save min-h-10 items-center gap-2 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark hover:shadow-rt-glow active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                >
                    <i class="far fa-check" aria-hidden="true"></i>
                    {{ $labels['saveAndClose'] }}
                </button>
            </x-slot:actions>

            <main class="h-full min-h-0">
                @include('livewire.operations.partials.wagon-desktop-workspace', [
                    'sheetInput' => $sheetInput,
                    'inputClass' => $inputClass,
                    'labelClass' => $labelClass,
                ])

                @include('livewire.operations.partials.wagon-mobile-wizard', [
                    'inputClass' => $inputClass,
                    'labelClass' => $labelClass,
                ])
            </main>
        </x-ui.fullscreen-modal>
    </x-ui.page>
</div>
