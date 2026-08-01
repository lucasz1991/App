@php
    $isGerman = app()->getLocale() === 'de';
@endphp

<div
    class="space-y-5"
    x-data="{ saved: false }"
    x-on:knowledge-pool-saved.window="saved = true; window.setTimeout(() => saved = false, 2200)"
    data-assistant-knowledge-manager
>
    <section class="relative overflow-hidden rounded-2xl bg-slate-950 px-5 py-5 text-white shadow-rt-md ring-1 ring-white/10 sm:px-6">
        <div class="pointer-events-none absolute -right-12 -top-16 h-44 w-44 rounded-full bg-rt-red/25 blur-3xl" aria-hidden="true"></div>
        <div class="relative grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="max-w-3xl">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-red-300">
                    <i class="fad fa-books" aria-hidden="true"></i>
                    {{ $isGerman ? 'RailTime Assist · kuratiertes Wissen' : 'RailTime Assist · curated knowledge' }}
                </div>
                <h3 class="mt-2 text-balance text-xl font-semibold tracking-[-0.025em] sm:text-2xl">
                    {{ $isGerman ? 'Kleine Basis, gezielte Details' : 'Small baseline, precise details' }}
                </h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    {{ $isGerman
                        ? 'Nur der Basistext und markierte Kurzinfos begleiten jede Anfrage. Vollständige Einträge ruft der Chatbot ausschließlich über das freigegebene Such-Tool ab, wenn sie wirklich benötigt werden.'
                        : 'Only the baseline and selected summaries accompany every request. The chatbot retrieves full entries through the approved search tool only when needed.' }}
                </p>
            </div>

            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="min-w-[5.25rem] rounded-xl bg-white/7 px-3 py-3 ring-1 ring-white/10">
                    <strong class="block text-xl font-semibold">{{ $topics->count() }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Themen' : 'Topics' }}</span>
                </div>
                <div class="min-w-[5.25rem] rounded-xl bg-white/7 px-3 py-3 ring-1 ring-white/10">
                    <strong class="block text-xl font-semibold">{{ $activeEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Aktiv' : 'Active' }}</span>
                </div>
                <div class="min-w-[5.25rem] rounded-xl bg-white/7 px-3 py-3 ring-1 ring-white/10">
                    <strong class="block text-xl font-semibold text-red-300">{{ $baselineEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $isGerman ? 'Basis' : 'Baseline' }}</span>
                </div>
            </div>
        </div>
    </section>

    <section class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 max-w-2xl">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-sparkles" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Kompakter Basistext' : 'Compact baseline text' }}</h4>
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman ? 'Wird bei jeder Chat-Anfrage mitgegeben. Präzise, zeitlos und ohne sensible Daten halten.' : 'Sent with every chat request. Keep it precise, durable and free of sensitive data.' }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span x-cloak x-show="saved" x-transition class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    <i class="far fa-check-circle mr-1" aria-hidden="true"></i>{{ $isGerman ? 'Gespeichert' : 'Saved' }}
                </span>
                <x-button type="button" wire:click="saveIntro" wire:loading.attr="disabled" wire:target="saveIntro">
                    <span wire:loading.remove wire:target="saveIntro"><i class="far fa-save mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Basistext speichern' : 'Save baseline' }}</span>
                    <span wire:loading wire:target="saveIntro">{{ $isGerman ? 'Speichert …' : 'Saving …' }}</span>
                </x-button>
            </div>
        </div>
        <x-ui.forms.textarea
            id="assistant-knowledge-intro"
            wire:model="knowledgeIntro"
            rows="3"
            maxlength="1200"
            class="mt-4"
        />
        <div class="mt-2 flex items-start justify-between gap-3 text-xs text-rt-muted dark:text-rt-dark-muted">
            <x-input-error for="knowledgeIntro" />
            <span class="shrink-0">{{ mb_strlen($knowledgeIntro) }}/1200</span>
        </div>
    </section>

    <section class="grid min-h-[32rem] overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <aside class="border-b border-rt-border/70 bg-rt-surface-muted/45 p-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/25 lg:border-b-0 lg:border-r">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Themen' : 'Topics' }}</h4>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Struktur für die Wissenssuche' : 'Structure for knowledge search' }}</p>
                </div>
                <button
                    type="button"
                    wire:click="createTopic"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-red text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                    aria-label="{{ $isGerman ? 'Thema hinzufügen' : 'Add topic' }}"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="mt-4 flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible" aria-label="{{ $isGerman ? 'Wissensthemen' : 'Knowledge topics' }}">
                <button
                    type="button"
                    wire:click="$set('topicFilter', 'all')"
                    @class([
                        'flex min-w-[12rem] items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition lg:min-w-0',
                        'bg-rt-red text-white shadow-sm' => $topicFilter === 'all',
                        'text-rt-text hover:bg-rt-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface' => $topicFilter !== 'all',
                    ])
                >
                    <span class="flex min-w-0 items-center gap-2"><i class="fad fa-layer-group" aria-hidden="true"></i><span class="truncate">{{ $isGerman ? 'Alle Einträge' : 'All entries' }}</span></span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-[11px]',
                        'bg-white/15 text-white' => $topicFilter === 'all',
                        'bg-rt-surface text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted' => $topicFilter !== 'all',
                    ])>{{ $activeEntryCount }}</span>
                </button>

                @foreach ($topics as $topic)
                    <div wire:key="knowledge-topic-{{ $topic->id }}" class="group relative min-w-[12rem] lg:min-w-0">
                        <button
                            type="button"
                            wire:click="$set('topicFilter', '{{ $topic->id }}')"
                            @class([
                                'flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 pr-16 text-left text-sm transition',
                                'bg-rt-accent-soft font-semibold text-rt-red ring-1 ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent' => $topicFilter === (string) $topic->id,
                                'text-rt-text hover:bg-rt-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface' => $topicFilter !== (string) $topic->id,
                                'opacity-55' => ! $topic->is_active,
                            ])
                        >
                            <span class="min-w-0 truncate">{{ $topic->name }}</span>
                            <span class="text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ $topic->active_entries_count }}</span>
                        </button>
                        <button type="button" wire:click="editTopic({{ $topic->id }})" class="absolute right-8 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-rt-muted opacity-70 transition hover:bg-white hover:text-rt-red group-hover:opacity-100 dark:hover:bg-rt-dark-surface" aria-label="{{ $isGerman ? 'Thema bearbeiten' : 'Edit topic' }}">
                            <i class="far fa-pen" aria-hidden="true"></i>
                        </button>
                        <button type="button" wire:click="deleteTopic({{ $topic->id }})" wire:confirm="{{ $isGerman ? 'Thema und alle enthaltenen Einträge wirklich entfernen?' : 'Remove this topic and all of its entries?' }}" class="absolute right-1 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-rt-muted opacity-70 transition hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-950/30" aria-label="{{ $isGerman ? 'Thema löschen' : 'Delete topic' }}">
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
                        class="w-full rounded-xl border-0 bg-rt-surface-muted py-2.5 pl-10 pr-4 text-sm text-rt-text ring-1 ring-inset ring-rt-border/70 placeholder:text-rt-soft focus:ring-2 focus:ring-rt-red dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/70"
                    >
                </label>
                <x-button type="button" wire:click="createEntry" :disabled="$topics->isEmpty()">
                    <i class="far fa-plus mr-1.5" aria-hidden="true"></i>{{ $isGerman ? 'Information anlegen' : 'Add information' }}
                </x-button>
            </div>

            @if ($topics->isEmpty())
                <div class="mt-5 flex min-h-[22rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/30 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/20">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-accent-soft text-xl text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i class="fad fa-folder-plus" aria-hidden="true"></i></span>
                    <h4 class="mt-4 font-semibold text-rt-text dark:text-rt-dark-text">{{ $isGerman ? 'Mit einem Thema beginnen' : 'Start with a topic' }}</h4>
                    <p class="mt-1 max-w-md text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Themen geben dem Such-Tool Kontext und halten den Wissenspool auch bei vielen Einträgen übersichtlich.' : 'Topics give the search tool context and keep a growing knowledge pool manageable.' }}</p>
                    <x-button type="button" wire:click="createTopic" class="mt-4"><i class="far fa-plus mr-1.5"></i>{{ $isGerman ? 'Erstes Thema anlegen' : 'Create first topic' }}</x-button>
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @forelse ($entries as $entry)
                        <article wire:key="knowledge-entry-{{ $entry->id }}" @class([
                            'group rounded-2xl border bg-rt-surface p-4 transition hover:-translate-y-px hover:shadow-rt-sm dark:bg-rt-dark-surface',
                            'border-rt-border/70 dark:border-rt-dark-border/70' => $entry->is_active,
                            'border-dashed border-rt-border/60 opacity-60 dark:border-rt-dark-border/60' => ! $entry->is_active,
                        ])>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                    <i class="fad fa-file-lines" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h5 class="min-w-0 truncate font-semibold text-rt-text dark:text-rt-dark-text">{{ $entry->title }}</h5>
                                        <span class="rounded-full bg-rt-surface-muted px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ $entry->topic->name }}</span>
                                        @if ($entry->include_in_baseline)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="far fa-sparkles mr-1"></i>{{ $isGerman ? 'Basisinfo' : 'Baseline' }}</span>
                                        @endif
                                        @unless ($entry->is_active)
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $isGerman ? 'Inaktiv' : 'Inactive' }}</span>
                                        @endunless
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
                                    <button type="button" wire:click="editEntry({{ $entry->id }})" class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-accent-soft hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft" aria-label="{{ $isGerman ? 'Information bearbeiten' : 'Edit information' }}"><i class="far fa-pen" aria-hidden="true"></i></button>
                                    <button type="button" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ $isGerman ? 'Diesen Wissenseintrag wirklich entfernen?' : 'Remove this knowledge entry?' }}" class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-red-500/15 dark:text-rt-dark-muted dark:hover:bg-red-950/30" aria-label="{{ $isGerman ? 'Information löschen' : 'Delete information' }}"><i class="far fa-trash" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/25 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/15">
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
            <div class="space-y-5">
                <div>
                    <x-ui.forms.label for="knowledge-topic-name" :value="$isGerman ? 'Name' : 'Name'" />
                    <x-ui.forms.input id="knowledge-topic-name" type="text" wire:model="topicName" maxlength="120" class="mt-1" />
                    <x-input-error for="topicName" class="mt-1" />
                </div>
                <div>
                    <x-ui.forms.label for="knowledge-topic-description" :value="$isGerman ? 'Kurze Beschreibung für die KI' : 'Short description for the AI'" />
                    <x-ui.forms.textarea id="knowledge-topic-description" wire:model="topicDescription" rows="4" maxlength="500" class="mt-1" />
                    <x-input-error for="topicDescription" class="mt-1" />
                </div>
                <x-ui.forms.toggle-button model="topicActive" :label="$isGerman ? 'Thema für den Chatbot freigeben' : 'Enable topic for the chatbot'" />
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-secondary-button wire:click="$set('topicEditorOpen', false)">{{ $isGerman ? 'Abbrechen' : 'Cancel' }}</x-secondary-button>
            <x-button class="ml-2" wire:click="saveTopic" wire:loading.attr="disabled" wire:target="saveTopic">{{ $isGerman ? 'Thema speichern' : 'Save topic' }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="entryEditorOpen" maxWidth="3xl">
        <x-slot:title>{{ $editingEntryId ? ($isGerman ? 'Information bearbeiten' : 'Edit information') : ($isGerman ? 'Information anlegen' : 'Create information') }}</x-slot:title>
        <x-slot:content>
            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,.55fr)]">
                    <div>
                        <x-ui.forms.label for="knowledge-entry-title" :value="$isGerman ? 'Titel' : 'Title'" />
                        <x-ui.forms.input id="knowledge-entry-title" type="text" wire:model="entryTitle" maxlength="180" class="mt-1" />
                        <x-input-error for="entryTitle" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.forms.label for="knowledge-entry-topic" :value="$isGerman ? 'Thema' : 'Topic'" />
                        <x-ui.forms.select id="knowledge-entry-topic" wire:model="entryTopicId" class="mt-1">
                            <option value="">{{ $isGerman ? 'Thema wählen' : 'Select topic' }}</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </x-ui.forms.select>
                        <x-input-error for="entryTopicId" class="mt-1" />
                    </div>
                </div>

                <div>
                    <div class="flex items-end justify-between gap-3">
                        <x-ui.forms.label for="knowledge-entry-summary" :value="$isGerman ? 'Kurzinfo' : 'Summary'" />
                        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Wird als Vorschau und optional als Basiswissen genutzt' : 'Used for previews and optional baseline knowledge' }}</span>
                    </div>
                    <x-ui.forms.textarea id="knowledge-entry-summary" wire:model="entrySummary" rows="3" maxlength="1000" class="mt-1" />
                    <x-input-error for="entrySummary" class="mt-1" />
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-content" :value="$isGerman ? 'Vollständige Information' : 'Full information'" />
                    <x-ui.forms.textarea id="knowledge-entry-content" wire:model="entryContent" rows="11" maxlength="50000" class="mt-1 font-mono text-sm leading-6" />
                    <div class="mt-1 flex items-start justify-between gap-3">
                        <x-input-error for="entryContent" />
                        <span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ mb_strlen($entryContent) }}/50000</span>
                    </div>
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-keywords" :value="$isGerman ? 'Suchbegriffe' : 'Search keywords'" />
                    <x-ui.forms.input id="knowledge-entry-keywords" type="text" wire:model="entryKeywords" maxlength="1000" class="mt-1" placeholder="{{ $isGerman ? 'z. B. Dienstplan, Schichttausch, Freigabe' : 'e.g. roster, shift swap, approval' }}" />
                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Mit Komma oder Semikolon trennen; maximal 16 Begriffe.' : 'Separate with commas or semicolons; up to 16 terms.' }}</p>
                    <x-input-error for="entryKeywords" class="mt-1" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><x-ui.forms.toggle-button model="entryActive" :label="$isGerman ? 'Für die Wissenssuche freigeben' : 'Enable for knowledge search'" /></div>
                    <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200/60 dark:bg-amber-950/20 dark:ring-amber-800/40"><x-ui.forms.toggle-button model="entryBaseline" :label="$isGerman ? 'Kurzinfo immer als Basiswissen senden' : 'Always send summary as baseline'" /></div>
                </div>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-secondary-button wire:click="$set('entryEditorOpen', false)">{{ $isGerman ? 'Abbrechen' : 'Cancel' }}</x-secondary-button>
            <x-button class="ml-2" wire:click="saveEntry" wire:loading.attr="disabled" wire:target="saveEntry">{{ $isGerman ? 'Information speichern' : 'Save information' }}</x-button>
        </x-slot:footer>
    </x-dialog-modal>
</div>
