@php
    $isGerman = app()->getLocale() === 'de';
@endphp

<div
    class="space-y-5"
    x-data="{
        notice: '',
        noticeTimer: null,
        showNotice(message) {
            window.clearTimeout(this.noticeTimer);
            this.notice = message || @js($isGerman ? 'Änderung gespeichert.' : 'Change saved.');
            this.noticeTimer = window.setTimeout(() => this.notice = '', 3200);
        },
        destroy() {
            window.clearTimeout(this.noticeTimer);
        },
    }"
    x-on:knowledge-pool-saved.window="showNotice($event.detail.message)"
    data-assistant-knowledge-manager
>
    <div
        x-cloak
        x-show="notice"
        x-transition.opacity.duration.150ms
        class="pointer-events-none fixed bottom-4 left-4 right-4 z-[220] flex min-h-11 items-center gap-3 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white shadow-rt-lg sm:left-auto sm:right-6 sm:max-w-sm"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <i class="far fa-check-circle shrink-0" aria-hidden="true"></i>
        <span x-text="notice"></span>
    </div>

    <section class="relative overflow-hidden rounded-2xl bg-slate-950 px-5 py-5 text-white shadow-rt-md ring-1 ring-white/10 sm:px-6">
        <div class="pointer-events-none absolute -right-12 -top-16 h-44 w-44 rounded-full bg-rt-red/25 blur-3xl" aria-hidden="true"></div>
        <div class="relative grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="max-w-3xl">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-red-300">
                    <i class="fad fa-books" aria-hidden="true"></i>
                    {{ $isGerman ? 'RailTime Assist · kuratiertes Wissen' : 'RailTime Assist · curated knowledge' }}
                </div>
                <h3 class="mt-2 [text-wrap:balance] text-xl font-semibold tracking-[-0.025em] sm:text-2xl">
                    {{ $isGerman ? 'Klare Leitplanken, gezielte Details' : 'Clear guardrails, precise details' }}
                </h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    {{ $isGerman
                        ? 'Default-Prompt und verbindliche Regeln steuern jede Antwort. Der kompakte Wissenskontext begleitet die Anfrage; vollständige Einträge ruft der Chatbot nur bei Bedarf über das freigegebene Such-Tool ab.'
                        : 'The default prompt and binding rules guide every answer. Compact knowledge accompanies the request; full entries are retrieved through the approved search tool only when needed.' }}
                </p>
            </div>

            <div class="grid w-full grid-cols-3 gap-2 text-center lg:w-auto">
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold">{{ $topics->count() }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Themen' : 'Topics' }}</span>
                </div>
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold">{{ $activeEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Aktiv' : 'Active' }}</span>
                </div>
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold text-red-300">{{ $baselineEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Basis' : 'Baseline' }}</span>
                </div>
            </div>
        </div>
    </section>

    <section
        class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
        x-data="{
            dirty: false,
            promptCount: @js(mb_strlen($assistantDefaultPrompt)),
            rulesCount: @js(mb_strlen($assistantRules)),
        }"
        x-on:knowledge-pool-saved.window="
            if (($event.detail.fields || []).some(field => ['assistantDefaultPrompt', 'assistantRules'].includes(field))) dirty = false
        "
        data-assistant-prompt-rules
        data-assistant-instruction-settings
        data-assistant-trust-level="system"
    >
        <div class="border-b border-rt-border/70 bg-gradient-to-r from-rt-accent-soft/60 via-transparent to-amber-50/70 px-4 py-4 dark:border-rt-dark-border/70 dark:from-rt-dark-accent-soft/35 dark:to-amber-950/15 sm:px-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-red text-white shadow-sm">
                        <i class="fad fa-shield-alt" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 id="assistant-prompt-rules-heading" class="font-semibold text-rt-text dark:text-rt-dark-text">
                                {{ $isGerman ? 'Verhalten & wichtige Regeln' : 'Behavior & important rules' }}
                            </h4>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ $isGerman ? 'Immer aktiv' : 'Always active' }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-3xl text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman
                                ? 'Diese vertrauenswürdigen Superadmin-Vorgaben werden vor dem Wissenskontext an jede Modellanfrage übergeben. Wissenseinträge bleiben davon getrennte Referenzdaten.'
                                : 'These trusted Superadmin instructions precede the knowledge context in every model request. Knowledge entries remain separate reference data.' }}
                        </p>
                    </div>
                </div>

                <div class="flex w-full items-center gap-3 lg:w-auto lg:justify-end">
                    <span x-cloak x-show="dirty" class="text-xs font-semibold text-amber-700 dark:text-amber-300" role="status">
                        <i class="far fa-circle-exclamation mr-1" aria-hidden="true"></i>{{ $isGerman ? 'Nicht gespeichert' : 'Unsaved' }}
                    </span>
                    <x-button
                        type="submit"
                        form="assistant-prompt-rules-form"
                        class="ml-auto min-h-11 w-full sm:w-auto lg:ml-0"
                        wire:loading.attr="disabled"
                        wire:target="savePromptConfiguration"
                    >
                        <span wire:loading.remove wire:target="savePromptConfiguration"><i class="far fa-save mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Prompt & Regeln speichern' : 'Save prompt & rules' }}</span>
                        <span wire:loading wire:target="savePromptConfiguration">{{ $isGerman ? 'Speichert …' : 'Saving …' }}</span>
                    </x-button>
                </div>
            </div>
        </div>

        <form id="assistant-prompt-rules-form" wire:submit="savePromptConfiguration" class="grid gap-4 p-4 xl:grid-cols-2 sm:p-5">
            <article class="flex min-w-0 flex-col rounded-2xl bg-rt-surface-muted/60 p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/25 dark:ring-rt-dark-border/60" data-prompt-layer="trusted-default">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-brain" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <x-ui.forms.label for="assistant-default-prompt" :value="$isGerman ? 'Default-Prompt' : 'Default prompt'" />
                        <p id="assistant-default-prompt-help" class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman ? 'Beschreibt Rolle, Ton und Hauptaufgabe des Assistenten. Keine Zugangsdaten oder personenbezogenen Daten eintragen.' : 'Defines the assistant’s role, tone and primary task. Do not enter credentials or personal data.' }}
                        </p>
                    </div>
                </div>
                <x-ui.forms.textarea
                    id="assistant-default-prompt"
                    wire:model="assistantDefaultPrompt"
                    x-on:input="dirty = true; promptCount = Array.from($event.target.value).length"
                    rows="7"
                    maxlength="3000"
                    required
                    aria-required="true"
                    :aria-invalid="$errors->has('assistantDefaultPrompt') ? 'true' : 'false'"
                    aria-describedby="assistant-default-prompt-help{{ $errors->has('assistantDefaultPrompt') ? ' assistantdefaultprompt-error' : '' }}"
                    class="mt-4 min-h-44"
                />
                <div class="mt-2 flex items-start justify-between gap-3 text-xs text-rt-muted dark:text-rt-dark-muted">
                    <x-input-error for="assistantDefaultPrompt" />
                    <span class="shrink-0" aria-live="off"><span x-text="promptCount">{{ mb_strlen($assistantDefaultPrompt) }}</span>/3000</span>
                </div>
            </article>

            <article class="flex min-w-0 flex-col rounded-2xl bg-amber-50/75 p-4 ring-1 ring-amber-200/70 dark:bg-amber-950/15 dark:ring-amber-800/40" data-prompt-layer="trusted-rules">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        <i class="fad fa-gavel" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <x-ui.forms.label for="assistant-important-rules" :value="$isGerman ? 'Wichtige Regeln' : 'Important rules'" />
                        <p id="assistant-important-rules-help" class="mt-0.5 text-xs leading-5 text-amber-900/80 dark:text-amber-200/80">
                            {{ $isGerman ? 'Am besten eine eindeutige Regel pro Zeile. Diese Regeln haben Vorrang vor Wissenseinträgen, nicht aber vor dem fest eingebauten Plattform-Schutz.' : 'Prefer one unambiguous rule per line. These rules take precedence over knowledge entries, but not over built-in platform protection.' }}
                        </p>
                    </div>
                </div>
                <x-ui.forms.textarea
                    id="assistant-important-rules"
                    wire:model="assistantRules"
                    x-on:input="dirty = true; rulesCount = Array.from($event.target.value).length"
                    rows="7"
                    maxlength="4000"
                    required
                    aria-required="true"
                    :aria-invalid="$errors->has('assistantRules') ? 'true' : 'false'"
                    aria-describedby="assistant-important-rules-help{{ $errors->has('assistantRules') ? ' assistantrules-error' : '' }}"
                    class="mt-4 min-h-44 border-amber-300/80 focus:border-amber-500 focus:ring-amber-500/25 dark:border-amber-800/70"
                />
                <div class="mt-2 flex items-start justify-between gap-3 text-xs text-amber-900/75 dark:text-amber-200/75">
                    <x-input-error for="assistantRules" />
                    <span class="shrink-0" aria-live="off"><span x-text="rulesCount">{{ mb_strlen($assistantRules) }}</span>/4000</span>
                </div>
            </article>

            <div class="xl:col-span-2 flex items-start gap-2.5 rounded-xl bg-slate-950 px-3.5 py-3 text-xs leading-5 text-slate-300" role="note">
                <i class="fad fa-lock mt-0.5 shrink-0 text-red-300" aria-hidden="true"></i>
                <span>{{ $isGerman ? 'RailTime ergänzt weiterhin feste Sicherheitsregeln: keine erfundenen Aktionen, kein Zugriff auf Live- oder Personaldaten und keine Geheimnisse. Diese Schutzebene ist absichtlich nicht editierbar.' : 'RailTime still adds fixed safety rules: no fabricated actions, no access to live or personal data, and no secrets. This protection layer is intentionally not editable.' }}</span>
            </div>
        </form>
    </section>

    <section
        class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:p-5"
        x-data="{ dirty: false, count: @js(mb_strlen($knowledgeIntro)) }"
        x-on:knowledge-pool-saved.window="if (($event.detail.fields || []).includes('knowledgeIntro')) dirty = false"
        data-assistant-trust-level="reference"
    >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 max-w-2xl">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-sparkles" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Kompakter Wissenskontext' : 'Compact knowledge context' }}</h4>
                        <p id="assistant-knowledge-intro-help" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman ? 'Wird als reine Referenz bei jeder Anfrage mitgegeben und darf Prompt oder Regeln niemals überschreiben. Präzise, zeitlos und ohne sensible Daten halten.' : 'Sent as reference data with every request and can never override the prompt or rules. Keep it precise, durable and free of sensitive data.' }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span x-cloak x-show="dirty" class="text-xs font-semibold text-amber-700 dark:text-amber-300" role="status">
                    <i class="far fa-circle-exclamation mr-1" aria-hidden="true"></i>{{ $isGerman ? 'Nicht gespeichert' : 'Unsaved' }}
                </span>
                <x-button type="button" class="min-h-11" wire:click="saveIntro" wire:loading.attr="disabled" wire:target="saveIntro">
                    <span wire:loading.remove wire:target="saveIntro"><i class="far fa-save mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Basistext speichern' : 'Save baseline' }}</span>
                    <span wire:loading wire:target="saveIntro">{{ $isGerman ? 'Speichert …' : 'Saving …' }}</span>
                </x-button>
            </div>
        </div>
        <x-ui.forms.label
            for="assistant-knowledge-intro"
            :value="$isGerman ? 'Basistext (Pflichtfeld)' : 'Baseline text (required)'"
            class="sr-only"
        />
        <x-ui.forms.textarea
            id="assistant-knowledge-intro"
            wire:model="knowledgeIntro"
            x-ref="intro"
            x-on:input="dirty = true; count = Array.from($event.target.value).length"
            rows="3"
            maxlength="1200"
            required
            aria-required="true"
            :aria-invalid="$errors->has('knowledgeIntro') ? 'true' : 'false'"
            aria-describedby="assistant-knowledge-intro-help{{ $errors->has('knowledgeIntro') ? ' knowledgeintro-error' : '' }}"
            class="mt-4"
        />
        <div class="mt-2 flex items-start justify-between gap-3 text-xs text-rt-muted dark:text-rt-dark-muted">
            <x-input-error for="knowledgeIntro" />
            <span class="shrink-0" aria-live="off"><span x-text="count">{{ mb_strlen($knowledgeIntro) }}</span>/1200</span>
        </div>
    </section>

    <section class="grid min-h-[32rem] overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 lg:grid-cols-[18rem_minmax(0,1fr)]" data-assistant-trust-level="reference">
        <aside class="border-b border-rt-border/70 bg-rt-surface-muted/50 p-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/25 lg:border-b-0 lg:border-r">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Themen' : 'Topics' }}</h4>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Struktur für die Wissenssuche' : 'Structure for knowledge search' }}</p>
                </div>
                <button
                    type="button"
                    wire:click="createTopic"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-red text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                    aria-label="{{ $isGerman ? 'Thema hinzufügen' : 'Add topic' }}"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="mt-4 flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible" aria-label="{{ $isGerman ? 'Wissensthemen' : 'Knowledge topics' }}">
                <button
                    type="button"
                    wire:click="$set('topicFilter', 'all')"
                    aria-pressed="{{ $topicFilter === 'all' ? 'true' : 'false' }}"
                    @class([
                        'flex min-h-11 min-w-[12rem] items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 lg:min-w-0',
                        'bg-rt-red text-white shadow-sm' => $topicFilter === 'all',
                        'text-rt-text hover:bg-rt-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface' => $topicFilter !== 'all',
                    ])
                >
                    <span class="flex min-w-0 items-center gap-2"><i class="fad fa-layer-group" aria-hidden="true"></i><span class="truncate">{{ $isGerman ? 'Alle Einträge' : 'All entries' }}</span></span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-[11px]',
                        'bg-white/20 text-white' => $topicFilter === 'all',
                        'bg-rt-surface text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted' => $topicFilter !== 'all',
                    ]) data-knowledge-count="all">{{ $totalEntryCount }}</span>
                </button>

                @foreach ($topics as $topic)
                    <div wire:key="knowledge-topic-{{ $topic->id }}" class="group relative min-w-[12rem] lg:min-w-0">
                        <button
                            type="button"
                            wire:click="$set('topicFilter', '{{ $topic->id }}')"
                            aria-pressed="{{ $topicFilter === (string) $topic->id ? 'true' : 'false' }}"
                            @class([
                                'flex min-h-11 w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 pr-24 text-left text-sm transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20',
                                'bg-rt-accent-soft font-semibold text-rt-red ring-1 ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent' => $topicFilter === (string) $topic->id,
                                'text-rt-text hover:bg-rt-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface' => $topicFilter !== (string) $topic->id,
                                'text-rt-soft dark:text-rt-dark-soft' => ! $topic->is_active,
                            ])
                        >
                            <span class="min-w-0 truncate">
                                {{ $topic->name }}
                                @unless ($topic->is_active)
                                    <span class="sr-only"> · {{ $isGerman ? 'Thema inaktiv' : 'Topic inactive' }}</span>
                                @endunless
                            </span>
                            <span class="text-[11px] text-rt-muted dark:text-rt-dark-muted" data-knowledge-count="topic-{{ $topic->id }}">{{ $topic->entries_count }}</span>
                        </button>
                        <button type="button" wire:click="editTopic({{ $topic->id }})" class="absolute right-11 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-white hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:hover:bg-rt-dark-surface" aria-label="{{ $isGerman ? 'Thema bearbeiten' : 'Edit topic' }}">
                            <i class="far fa-pen" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            x-on:click.prevent="{{ \App\Support\Ui\ConfirmationAction::alpine(
                                method: 'deleteTopic',
                                arguments: [$topic->id],
                                title: $isGerman ? 'Thema löschen' : 'Delete topic',
                                message: $isGerman ? 'Thema und alle enthaltenen Informationen wirklich entfernen?' : 'Remove this topic and all of its information?',
                                confirmLabel: $isGerman ? 'Thema löschen' : 'Delete topic',
                            ) }}"
                            class="absolute right-0 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-red-500/20 dark:hover:bg-red-950/30"
                            aria-label="{{ $isGerman ? 'Thema löschen' : 'Delete topic' }}"
                        >
                            <i class="far fa-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0 p-4 sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <label class="relative block min-w-0 flex-1">
                    <span class="sr-only">{{ $isGerman ? 'Wissenseinträge durchsuchen' : 'Search knowledge entries' }}</span>
                    <i class="far fa-search pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-rt-muted" aria-hidden="true"></i>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ $isGerman ? 'Titel, Stichwort oder Inhalt suchen …' : 'Search title, keyword or content …' }}"
                        class="min-h-11 w-full rounded-xl border-0 bg-rt-surface-muted py-2.5 pl-10 pr-4 text-sm text-rt-text ring-1 ring-inset ring-rt-border/70 placeholder:text-rt-soft focus:ring-2 focus:ring-rt-red dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70"
                    >
                </label>
                <x-button type="button" class="min-h-11" wire:click="createEntry" :disabled="$topics->isEmpty()">
                    <i class="far fa-plus mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Information anlegen' : 'Add information' }}
                </x-button>
            </div>

            @if ($topics->isEmpty())
                <div class="mt-5 flex min-h-[22rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/30 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/20">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-accent-soft text-xl text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i class="fad fa-folder-plus" aria-hidden="true"></i></span>
                    <h4 class="mt-4 font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Mit einem Thema beginnen' : 'Start with a topic' }}</h4>
                    <p class="mt-1 max-w-md text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Themen geben dem Such-Tool Kontext und halten den Wissenspool auch bei vielen Einträgen übersichtlich.' : 'Topics give the search tool context and keep a growing knowledge pool manageable.' }}</p>
                    <x-button type="button" wire:click="createTopic" class="mt-4 min-h-11"><i class="far fa-plus mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Erstes Thema anlegen' : 'Create first topic' }}</x-button>
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @forelse ($entries as $entry)
                        @php
                            $entryEffectivelyActive = (bool) $entry->is_active && (bool) $entry->topic?->is_active;
                        @endphp
                        <article wire:key="knowledge-entry-{{ $entry->id }}" @class([
                            'group rounded-2xl border bg-rt-surface p-4 transition hover:-translate-y-px hover:shadow-rt-sm dark:bg-rt-dark-surface',
                            'border-rt-border/70 dark:border-rt-dark-border/70' => $entryEffectivelyActive,
                            'border-dashed border-amber-300/70 dark:border-amber-700/60' => ! $entryEffectivelyActive,
                        ])>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                    <i class="fad fa-file-alt" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h5 class="min-w-0 truncate font-semibold text-rt-text dark:text-rt-dark-text">{{ $entry->title }}</h5>
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                            'bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted' => $entry->topic->is_active,
                                            'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' => ! $entry->topic->is_active,
                                        ])>{{ $entry->topic->name }}</span>
                                        @if ($entry->include_in_baseline)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="far fa-sparkles mr-1" aria-hidden="true"></i>{{ $isGerman ? 'Basisinfo' : 'Baseline' }}</span>
                                        @endif
                                        @if ($entryEffectivelyActive)
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $isGerman ? 'Abrufbar' : 'Available' }}</span>
                                        @elseif (! $entry->topic->is_active)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ $isGerman ? 'Thema inaktiv' : 'Topic inactive' }}</span>
                                        @else
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $isGerman ? 'Inaktiv' : 'Inactive' }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1.5 line-clamp-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                        {{ $entry->summary ?: \Illuminate\Support\Str::limit(strip_tags($entry->content), 240) }}
                                    </p>
                                    @if ((array) $entry->keywords !== [])
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach (array_slice((array) $entry->keywords, 0, 6) as $keyword)
                                                <span class="rounded-md bg-rt-surface-muted px-2 py-1 text-[11px] text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">#{{ $keyword }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" wire:click="editEntry({{ $entry->id }})" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-accent-soft hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft" aria-label="{{ $isGerman ? 'Information bearbeiten' : 'Edit information' }}"><i class="far fa-pen" aria-hidden="true"></i></button>
                                    <button
                                        type="button"
                                        x-on:click.prevent="{{ \App\Support\Ui\ConfirmationAction::alpine(
                                            method: 'deleteEntry',
                                            arguments: [$entry->id],
                                            title: $isGerman ? 'Information löschen' : 'Delete information',
                                            message: $isGerman ? 'Diesen Wissenseintrag wirklich entfernen?' : 'Remove this knowledge entry?',
                                            confirmLabel: $isGerman ? 'Information löschen' : 'Delete information',
                                        ) }}"
                                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-red-500/20 dark:text-rt-dark-muted dark:hover:bg-red-950/30"
                                        aria-label="{{ $isGerman ? 'Information löschen' : 'Delete information' }}"
                                    ><i class="far fa-trash" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/25 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/20">
                            <i class="fad fa-search text-3xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                            <h4 class="mt-3 font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Keine passenden Informationen' : 'No matching information' }}</h4>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Suche anpassen oder einen neuen Eintrag anlegen.' : 'Adjust the search or create a new entry.' }}</p>
                        </div>
                    @endforelse
                </div>

                @if ($entries->hasPages())
                    <div class="mt-5">{{ $entries->links() }}</div>
                @endif
            @endif
        </div>
    </section>

    <div class="flex items-start gap-3 rounded-2xl border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950 ring-1 ring-amber-200/70 dark:bg-amber-950/25 dark:text-amber-100 dark:ring-amber-800/40">
        <i class="fad fa-shield-check mt-0.5 text-amber-600 dark:text-amber-300" aria-hidden="true"></i>
        <p>{{ $isGerman
            ? 'Aktive Wissenseinträge können bei einer passenden Frage auszugsweise an das konfigurierte OpenRouter-Textmodell übertragen werden. Keine Passwörter, API-Schlüssel, personenbezogenen Daten oder Betriebsgeheimnisse hinterlegen.'
            : 'Active knowledge entries may be sent in excerpts to the configured OpenRouter text model when relevant. Do not store passwords, API keys, personal data or trade secrets.' }}</p>
    </div>

    <x-dialog-modal wire:model="topicEditorOpen" maxWidth="lg">
        <x-slot:title>{{ $editingTopicId ? ($isGerman ? 'Thema bearbeiten' : 'Edit topic') : ($isGerman ? 'Thema anlegen' : 'Create topic') }}</x-slot:title>
        <x-slot:content>
            <form
                id="assistant-knowledge-topic-form"
                wire:submit="saveTopic"
                class="space-y-5"
            >
                <div>
                    <x-ui.forms.label for="knowledge-topic-name" :value="$isGerman ? 'Name (Pflichtfeld)' : 'Name (required)'" />
                    <x-ui.forms.input
                        id="knowledge-topic-name"
                        type="text"
                        wire:model="topicName"
                        maxlength="120"
                        required
                        aria-required="true"
                        :aria-invalid="$errors->has('topicName') ? 'true' : 'false'"
                        aria-describedby="{{ $errors->has('topicName') ? 'topicname-error' : '' }}"
                        class="mt-1"
                    />
                    <x-input-error for="topicName" class="mt-1" />
                </div>
                <div>
                    <x-ui.forms.label for="knowledge-topic-description" :value="$isGerman ? 'Kurze Beschreibung für die KI' : 'Short description for the AI'" />
                    <x-ui.forms.textarea
                        id="knowledge-topic-description"
                        wire:model="topicDescription"
                        rows="4"
                        maxlength="500"
                        :aria-invalid="$errors->has('topicDescription') ? 'true' : 'false'"
                        aria-describedby="{{ $errors->has('topicDescription') ? 'topicdescription-error' : '' }}"
                        class="mt-1"
                    />
                    <x-input-error for="topicDescription" class="mt-1" />
                </div>
                <x-ui.forms.toggle-button model="topicActive" :label="$isGerman ? 'Thema für den Chatbot freigeben' : 'Enable topic for the chatbot'" />
            </form>
        </x-slot:content>
        <x-slot:footer>
            <span
                wire:dirty
                wire:target="topicName,topicDescription,topicActive"
                class="mr-auto inline-flex items-center gap-2 text-left text-xs font-semibold text-amber-700 dark:text-amber-300"
                role="status"
            >
                <i class="far fa-circle-exclamation" aria-hidden="true"></i>
                {{ $isGerman ? 'Nicht gespeicherte Änderungen' : 'Unsaved changes' }}
            </span>
            <x-secondary-button type="button" class="min-h-11" wire:click="$set('topicEditorOpen', false)">{{ $isGerman ? 'Abbrechen' : 'Cancel' }}</x-secondary-button>
            <x-button type="submit" form="assistant-knowledge-topic-form" class="ml-2 min-h-11" wire:loading.attr="disabled" wire:target="saveTopic">{{ $isGerman ? 'Thema speichern' : 'Save topic' }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="entryEditorOpen" maxWidth="3xl">
        <x-slot:title>{{ $editingEntryId ? ($isGerman ? 'Information bearbeiten' : 'Edit information') : ($isGerman ? 'Information anlegen' : 'Create information') }}</x-slot:title>
        <x-slot:content>
            @php
                $baselineCapacityReached = $baselineAssignedCount >= $baselineLimit && ! $entryOriginallyBaseline;
            @endphp
            <form
                id="assistant-knowledge-entry-form"
                wire:submit="saveEntry"
                class="space-y-5"
                x-data="{ count: @js(mb_strlen($entryContent)) }"
                x-effect="
                    if ($wire.entryEditorOpen) $nextTick(() => count = Array.from($refs.content?.value || '').length);
                "
            >
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,.55fr)]">
                    <div>
                        <x-ui.forms.label for="knowledge-entry-title" :value="$isGerman ? 'Titel (Pflichtfeld)' : 'Title (required)'" />
                        <x-ui.forms.input
                            id="knowledge-entry-title"
                            type="text"
                            wire:model="entryTitle"
                            maxlength="180"
                            required
                            aria-required="true"
                            :aria-invalid="$errors->has('entryTitle') ? 'true' : 'false'"
                            aria-describedby="{{ $errors->has('entryTitle') ? 'entrytitle-error' : '' }}"
                            class="mt-1"
                        />
                        <x-input-error for="entryTitle" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.forms.label for="knowledge-entry-topic" :value="$isGerman ? 'Thema (Pflichtfeld)' : 'Topic (required)'" />
                        <x-ui.forms.select
                            id="knowledge-entry-topic"
                            wire:model="entryTopicId"
                            :aria-label="$isGerman ? 'Thema auswählen' : 'Select topic'"
                            :aria-invalid="$errors->has('entryTopicId') ? 'true' : 'false'"
                            aria-describedby="{{ $errors->has('entryTopicId') ? 'entrytopicid-error' : '' }}"
                            required
                            class="mt-1"
                        >
                            <option value="">{{ $isGerman ? 'Thema wählen' : 'Select topic' }}</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}{{ $topic->is_active ? '' : ($isGerman ? ' · Inaktiv' : ' · Inactive') }}</option>
                            @endforeach
                        </x-ui.forms.select>
                        <x-input-error for="entryTopicId" class="mt-1" />
                    </div>
                </div>

                <div>
                    <div class="flex items-end justify-between gap-3">
                        <x-ui.forms.label for="knowledge-entry-summary" :value="$isGerman ? 'Kurzinfo' : 'Summary'" />
                        <span id="knowledge-entry-summary-help" class="text-right text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Pflichtfeld, sobald Basiswissen aktiviert ist' : 'Required when baseline knowledge is enabled' }}</span>
                    </div>
                    <x-ui.forms.textarea
                        id="knowledge-entry-summary"
                        wire:model="entrySummary"
                        rows="3"
                        maxlength="1000"
                        :required="$entryBaseline"
                        :aria-required="$entryBaseline ? 'true' : 'false'"
                        :aria-invalid="$errors->has('entrySummary') ? 'true' : 'false'"
                        aria-describedby="knowledge-entry-summary-help{{ $errors->has('entrySummary') ? ' entrysummary-error' : '' }}"
                        class="mt-1"
                    />
                    <x-input-error for="entrySummary" class="mt-1" />
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-content" :value="$isGerman ? 'Vollständige Information (Pflichtfeld)' : 'Full information (required)'" />
                    <x-ui.forms.textarea
                        id="knowledge-entry-content"
                        x-ref="content"
                        x-on:input="count = Array.from($event.target.value).length"
                        wire:model="entryContent"
                        rows="11"
                        maxlength="50000"
                        required
                        aria-required="true"
                        :aria-invalid="$errors->has('entryContent') ? 'true' : 'false'"
                        aria-describedby="{{ $errors->has('entryContent') ? 'entrycontent-error' : '' }}"
                        class="mt-1 font-mono text-sm leading-6"
                    />
                    <div class="mt-1 flex items-start justify-between gap-3">
                        <x-input-error for="entryContent" />
                        <span class="text-xs text-rt-muted dark:text-rt-dark-muted" aria-live="off"><span x-text="count">{{ mb_strlen($entryContent) }}</span>/50000</span>
                    </div>
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-keywords" :value="$isGerman ? 'Suchbegriffe' : 'Search keywords'" />
                    <x-ui.forms.input
                        id="knowledge-entry-keywords"
                        type="text"
                        wire:model="entryKeywords"
                        maxlength="1000"
                        :aria-invalid="$errors->has('entryKeywords') ? 'true' : 'false'"
                        aria-describedby="knowledge-entry-keywords-help{{ $errors->has('entryKeywords') ? ' entrykeywords-error' : '' }}"
                        class="mt-1"
                        placeholder="{{ $isGerman ? 'z. B. Dienstplan, Schichttausch, Freigabe' : 'e.g. roster, shift swap, approval' }}"
                    />
                    <p id="knowledge-entry-keywords-help" class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Mit Komma oder Semikolon trennen; maximal 16 Begriffe.' : 'Separate with commas or semicolons; up to 16 terms.' }}</p>
                    <x-input-error for="entryKeywords" class="mt-1" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><x-ui.forms.toggle-button model="entryActive" :label="$isGerman ? 'Für die Wissenssuche freigeben' : 'Enable for knowledge search'" /></div>
                    <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200/60 dark:bg-amber-950/20 dark:ring-amber-800/40">
                        <x-ui.forms.toggle-button
                            id="knowledge-entry-baseline"
                            model="entryBaseline"
                            :disabled="$baselineCapacityReached"
                            :label="$isGerman ? 'Kurzinfo immer als Basiswissen senden' : 'Always send summary as baseline'"
                            :aria-invalid="$errors->has('entryBaseline') ? 'true' : 'false'"
                            aria-describedby="knowledge-entry-baseline-help{{ $errors->has('entryBaseline') ? ' entrybaseline-error' : '' }}"
                        />
                        <p id="knowledge-entry-baseline-help" class="mt-1.5 text-xs leading-5 text-amber-900 dark:text-amber-200">
                            {{ $baselineAssignedCount }}/{{ $baselineLimit }} {{ $isGerman ? 'Basisplätze belegt.' : 'baseline slots used.' }}
                            @if ($baselineCapacityReached)
                                {{ $isGerman ? 'Entferne zuerst die Basismarkierung bei einer anderen Information.' : 'Remove the baseline marker from another information entry first.' }}
                            @endif
                        </p>
                        <x-input-error for="entryBaseline" class="mt-1" />
                    </div>
                </div>

            </form>
        </x-slot:content>
        <x-slot:footer>
            <span
                wire:dirty
                wire:target="entryTopicId,entryTitle,entrySummary,entryContent,entryKeywords,entryActive,entryBaseline"
                class="mr-auto inline-flex items-center gap-2 text-left text-xs font-semibold text-amber-700 dark:text-amber-300"
                role="status"
            >
                <i class="far fa-circle-exclamation" aria-hidden="true"></i>
                {{ $isGerman ? 'Nicht gespeicherte Änderungen' : 'Unsaved changes' }}
            </span>
            <x-secondary-button type="button" class="min-h-11" wire:click="$set('entryEditorOpen', false)">{{ $isGerman ? 'Abbrechen' : 'Cancel' }}</x-secondary-button>
            <x-button type="submit" form="assistant-knowledge-entry-form" class="ml-2 min-h-11" wire:loading.attr="disabled" wire:target="saveEntry">{{ $isGerman ? 'Information speichern' : 'Save information' }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>
</div>
