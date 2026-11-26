<div
    class="space-y-5"
    x-data="{
        notice: '',
        noticeTimer: null,
        showNotice(message) {
            window.clearTimeout(this.noticeTimer);
            this.notice = message || @js(__('assistant_knowledge.notice_default'));
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
                    {{ __('assistant_knowledge.hero_eyebrow') }}
                </div>
                <h3 class="mt-2 [text-wrap:balance] text-xl font-semibold tracking-[-0.025em] sm:text-2xl">
                    {{ __('assistant_knowledge.hero_title') }}
                </h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    {{ __('assistant_knowledge.hero_text') }}
                </p>
            </div>

            <div class="grid w-full grid-cols-3 gap-2 text-center lg:w-auto">
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold">{{ $topics->count() }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('assistant_knowledge.topics') }}</span>
                </div>
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold">{{ $activeEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('assistant_knowledge.active') }}</span>
                </div>
                <div class="min-w-0 rounded-xl bg-white/10 px-2 py-3 ring-1 ring-white/10 sm:min-w-[5.25rem] sm:px-3">
                    <strong class="block text-xl font-semibold text-red-300">{{ $baselineEntryCount }}</strong>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('assistant_knowledge.baseline_short') }}</span>
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
                                {{ __('assistant_knowledge.rules_heading') }}
                            </h4>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('assistant_knowledge.always_active') }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-3xl text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('assistant_knowledge.rules_intro') }}
                        </p>
                    </div>
                </div>

                <div class="flex w-full items-center gap-3 lg:w-auto lg:justify-end">
                    <span x-cloak x-show="dirty" class="text-xs font-semibold text-amber-700 dark:text-amber-300" role="status">
                        <i class="far fa-exclamation-circle mr-1" aria-hidden="true"></i>{{ __('assistant_knowledge.unsaved') }}
                    </span>
                    <x-ui.buttons.button-basic mode="primary"
                        type="submit"
                        form="assistant-prompt-rules-form"
                        class="ml-auto min-h-11 w-full sm:w-auto lg:ml-0"
                        wire:loading.attr="disabled"
                        wire:target="savePromptConfiguration"
                    >
                        <span wire:loading.remove wire:target="savePromptConfiguration"><i class="far fa-save mr-1.5" aria-hidden="true"></i>{{ __('assistant_knowledge.save_prompt_rules') }}</span>
                        <span wire:loading wire:target="savePromptConfiguration">{{ __('assistant_knowledge.saving') }}</span>
                    </x-ui.buttons.button-basic>
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
                        <x-ui.forms.label for="assistant-default-prompt" :value="__('assistant_knowledge.default_prompt_label')" />
                        <p id="assistant-default-prompt-help" class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('assistant_knowledge.default_prompt_help') }}
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
                    <x-ui.forms.input-error for="assistantDefaultPrompt" />
                    <span class="shrink-0" aria-live="off"><span x-text="promptCount">{{ mb_strlen($assistantDefaultPrompt) }}</span>/3000</span>
                </div>
            </article>

            <article class="flex min-w-0 flex-col rounded-2xl bg-amber-50/75 p-4 ring-1 ring-amber-200/70 dark:bg-amber-950/15 dark:ring-amber-800/40" data-prompt-layer="trusted-rules">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        <i class="fad fa-gavel" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <x-ui.forms.label for="assistant-important-rules" :value="__('assistant_knowledge.important_rules_label')" />
                        <p id="assistant-important-rules-help" class="mt-0.5 text-xs leading-5 text-amber-900/80 dark:text-amber-200/80">
                            {{ __('assistant_knowledge.important_rules_help') }}
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
                    <x-ui.forms.input-error for="assistantRules" />
                    <span class="shrink-0" aria-live="off"><span x-text="rulesCount">{{ mb_strlen($assistantRules) }}</span>/4000</span>
                </div>
            </article>

            <div class="xl:col-span-2 flex items-start gap-2.5 rounded-xl bg-slate-950 px-3.5 py-3 text-xs leading-5 text-slate-300" role="note">
                <i class="fad fa-lock mt-0.5 shrink-0 text-red-300" aria-hidden="true"></i>
                <span>{{ __('assistant_knowledge.fixed_safety_note') }}</span>
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
                        <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __('assistant_knowledge.compact_context_heading') }}</h4>
                        <p id="assistant-knowledge-intro-help" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('assistant_knowledge.compact_context_help') }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span x-cloak x-show="dirty" class="text-xs font-semibold text-amber-700 dark:text-amber-300" role="status">
                    <i class="far fa-exclamation-circle mr-1" aria-hidden="true"></i>{{ __('assistant_knowledge.unsaved') }}
                </span>
                <x-ui.buttons.button-basic mode="primary" type="button" class="min-h-11" wire:click="saveIntro" wire:loading.attr="disabled" wire:target="saveIntro">
                    <span wire:loading.remove wire:target="saveIntro"><i class="far fa-save mr-1.5" aria-hidden="true"></i>{{ __('assistant_knowledge.save_baseline') }}</span>
                    <span wire:loading wire:target="saveIntro">{{ __('assistant_knowledge.saving') }}</span>
                </x-ui.buttons.button-basic>
            </div>
        </div>
        <x-ui.forms.label
            for="assistant-knowledge-intro"
            :value="__('assistant_knowledge.baseline_text_label')"
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
            <x-ui.forms.input-error for="knowledgeIntro" />
            <span class="shrink-0" aria-live="off"><span x-text="count">{{ mb_strlen($knowledgeIntro) }}</span>/1200</span>
        </div>
    </section>

    <section class="grid min-h-[32rem] overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 lg:grid-cols-[18rem_minmax(0,1fr)]" data-assistant-trust-level="reference">
        <aside class="border-b border-rt-border/70 bg-rt-surface-muted/50 p-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/25 lg:border-b-0 lg:border-r">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h4 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ __('assistant_knowledge.topics') }}</h4>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('assistant_knowledge.topics_subtitle') }}</p>
                </div>
                <button
                    type="button"
                    wire:click="createTopic"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-red text-white shadow-sm transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                    aria-label="{{ __('assistant_knowledge.add_topic') }}"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="mt-4 flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible" aria-label="{{ __('assistant_knowledge.topics_nav_label') }}">
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
                    <span class="flex min-w-0 items-center gap-2"><i class="fad fa-layer-group" aria-hidden="true"></i><span class="truncate">{{ __('assistant_knowledge.all_entries') }}</span></span>
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
                                    <span class="sr-only"> · {{ __('assistant_knowledge.topic_inactive') }}</span>
                                @endunless
                            </span>
                            <span class="text-[11px] text-rt-muted dark:text-rt-dark-muted" data-knowledge-count="topic-{{ $topic->id }}">{{ $topic->entries_count }}</span>
                        </button>
                        <button type="button" wire:click="editTopic({{ $topic->id }})" class="absolute right-11 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-white hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:hover:bg-rt-dark-surface" aria-label="{{ __('assistant_knowledge.edit_topic') }}">
                            <i class="far fa-pen" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            x-on:click.prevent="{{ \App\Support\Ui\ConfirmationAction::alpine(
                                method: 'deleteTopic',
                                arguments: [$topic->id],
                                title: __('assistant_knowledge.delete_topic'),
                                message: __('assistant_knowledge.delete_topic_confirm'),
                                confirmLabel: __('assistant_knowledge.delete_topic'),
                            ) }}"
                            class="absolute right-0 top-1/2 inline-flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-red-500/20 dark:hover:bg-red-950/30"
                            aria-label="{{ __('assistant_knowledge.delete_topic') }}"
                        >
                            <i class="far fa-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0 p-4 sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <x-ui.forms.search
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('assistant_knowledge.search_placeholder')"
                        :aria-label="__('assistant_knowledge.search_label')"
                    />
                </div>
                <x-ui.buttons.button-basic mode="primary" type="button" class="min-h-11" wire:click="createEntry" :disabled="$topics->isEmpty()">
                    <i class="far fa-plus mr-1.5" aria-hidden="true"></i>{{ __('assistant_knowledge.add_information') }}
                </x-ui.buttons.button-basic>
            </div>

            @if ($topics->isEmpty())
                <div class="mt-5 flex min-h-[22rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/30 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/20">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-accent-soft text-xl text-rt-red dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i class="fad fa-folder-plus" aria-hidden="true"></i></span>
                    <h4 class="mt-4 font-semibold text-rt-text dark:text-rt-dark-text">{{ __('assistant_knowledge.empty_topics_title') }}</h4>
                    <p class="mt-1 max-w-md text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('assistant_knowledge.empty_topics_text') }}</p>
                    <x-ui.buttons.button-basic mode="primary" type="button" wire:click="createTopic" class="mt-4 min-h-11"><i class="far fa-plus mr-1.5" aria-hidden="true"></i>{{ __('assistant_knowledge.create_first_topic') }}</x-ui.buttons.button-basic>
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
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="far fa-sparkles mr-1" aria-hidden="true"></i>{{ __('assistant_knowledge.baseline_badge') }}</span>
                                        @endif
                                        @if ($entryEffectivelyActive)
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ __('assistant_knowledge.available') }}</span>
                                        @elseif (! $entry->topic->is_active)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ __('assistant_knowledge.topic_inactive') }}</span>
                                        @else
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ __('assistant_knowledge.inactive') }}</span>
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
                                    <button type="button" wire:click="editEntry({{ $entry->id }})" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-accent-soft hover:text-rt-red focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft" aria-label="{{ __('assistant_knowledge.edit_information') }}"><i class="far fa-pen" aria-hidden="true"></i></button>
                                    <button
                                        type="button"
                                        x-on:click.prevent="{{ \App\Support\Ui\ConfirmationAction::alpine(
                                            method: 'deleteEntry',
                                            arguments: [$entry->id],
                                            title: __('assistant_knowledge.delete_information'),
                                            message: __('assistant_knowledge.delete_information_confirm'),
                                            confirmLabel: __('assistant_knowledge.delete_information'),
                                        ) }}"
                                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-red-500/20 dark:text-rt-dark-muted dark:hover:bg-red-950/30"
                                        aria-label="{{ __('assistant_knowledge.delete_information') }}"
                                    ><i class="far fa-trash" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="flex min-h-[20rem] flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/25 px-6 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/20">
                            <i class="fad fa-search text-3xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                            <h4 class="mt-3 font-semibold text-rt-text dark:text-rt-dark-text">{{ __('assistant_knowledge.no_results_title') }}</h4>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('assistant_knowledge.no_results_text') }}</p>
                        </div>
                    @endforelse
                </div>

                @if ($entries->hasPages())
                    <div class="mt-5"><x-tables.pagination :paginator="$entries" label="Wissensseiten" /></div>
                @endif
            @endif
        </div>
    </section>

    <div class="flex items-start gap-3 rounded-2xl border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950 ring-1 ring-amber-200/70 dark:bg-amber-950/25 dark:text-amber-100 dark:ring-amber-800/40">
        <i class="fad fa-shield-check mt-0.5 text-amber-600 dark:text-amber-300" aria-hidden="true"></i>
        <p>{{ __('assistant_knowledge.privacy_note') }}</p>
    </div>

    <x-dialog-modal wire:model="topicEditorOpen" maxWidth="lg">
        <x-slot:title>{{ $editingTopicId ? __('assistant_knowledge.edit_topic') : __('assistant_knowledge.create_topic') }}</x-slot:title>
        <x-slot:content>
            <form
                id="assistant-knowledge-topic-form"
                wire:submit="saveTopic"
                class="space-y-5"
            >
                <div>
                    <x-ui.forms.label for="knowledge-topic-name" :value="__('assistant_knowledge.topic_name_label')" />
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
                    <x-ui.forms.input-error for="topicName" class="mt-1" />
                </div>
                <div>
                    <x-ui.forms.label for="knowledge-topic-description" :value="__('assistant_knowledge.topic_description_label')" />
                    <x-ui.forms.textarea
                        id="knowledge-topic-description"
                        wire:model="topicDescription"
                        rows="4"
                        maxlength="500"
                        :aria-invalid="$errors->has('topicDescription') ? 'true' : 'false'"
                        aria-describedby="{{ $errors->has('topicDescription') ? 'topicdescription-error' : '' }}"
                        class="mt-1"
                    />
                    <x-ui.forms.input-error for="topicDescription" class="mt-1" />
                </div>
                <x-ui.forms.toggle-button model="topicActive" :label="__('assistant_knowledge.topic_active_label')" />
            </form>
        </x-slot:content>
        <x-slot:footer>
            <span
                wire:dirty
                wire:target="topicName,topicDescription,topicActive"
                class="mr-auto inline-flex items-center gap-2 text-left text-xs font-semibold text-amber-700 dark:text-amber-300"
                role="status"
            >
                <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                {{ __('assistant_knowledge.unsaved_changes') }}
            </span>
            <x-ui.buttons.button-basic mode="secondary" type="button" class="min-h-11" wire:click="$set('topicEditorOpen', false)">{{ __('assistant_knowledge.cancel') }}</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" type="submit" form="assistant-knowledge-topic-form" class="ml-2 min-h-11" wire:loading.attr="disabled" wire:target="saveTopic">{{ __('assistant_knowledge.save_topic') }}</x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="entryEditorOpen" maxWidth="3xl">
        <x-slot:title>{{ $editingEntryId ? __('assistant_knowledge.edit_information') : __('assistant_knowledge.create_information') }}</x-slot:title>
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
                        <x-ui.forms.label for="knowledge-entry-title" :value="__('assistant_knowledge.entry_title_label')" />
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
                        <x-ui.forms.input-error for="entryTitle" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.forms.label for="knowledge-entry-topic" :value="__('assistant_knowledge.entry_topic_label')" />
                        <x-ui.forms.select
                            id="knowledge-entry-topic"
                            wire:model="entryTopicId"
                            :aria-label="__('assistant_knowledge.select_topic_label')"
                            :aria-invalid="$errors->has('entryTopicId') ? 'true' : 'false'"
                            aria-describedby="{{ $errors->has('entryTopicId') ? 'entrytopicid-error' : '' }}"
                            required
                            class="mt-1"
                        >
                            <option value="">{{ __('assistant_knowledge.select_topic_option') }}</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}{{ $topic->is_active ? '' : __('assistant_knowledge.inactive_suffix') }}</option>
                            @endforeach
                        </x-ui.forms.select>
                        <x-ui.forms.input-error for="entryTopicId" class="mt-1" />
                    </div>
                </div>

                <div>
                    <div class="flex items-end justify-between gap-3">
                        <x-ui.forms.label for="knowledge-entry-summary" :value="__('assistant_knowledge.summary_label')" />
                        <span id="knowledge-entry-summary-help" class="text-right text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('assistant_knowledge.summary_help') }}</span>
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
                    <x-ui.forms.input-error for="entrySummary" class="mt-1" />
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-content" :value="__('assistant_knowledge.content_label')" />
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
                        <x-ui.forms.input-error for="entryContent" />
                        <span class="text-xs text-rt-muted dark:text-rt-dark-muted" aria-live="off"><span x-text="count">{{ mb_strlen($entryContent) }}</span>/50000</span>
                    </div>
                </div>

                <div>
                    <x-ui.forms.label for="knowledge-entry-keywords" :value="__('assistant_knowledge.keywords_label')" />
                    <x-ui.forms.input
                        id="knowledge-entry-keywords"
                        type="text"
                        wire:model="entryKeywords"
                        maxlength="1000"
                        :aria-invalid="$errors->has('entryKeywords') ? 'true' : 'false'"
                        aria-describedby="knowledge-entry-keywords-help{{ $errors->has('entryKeywords') ? ' entrykeywords-error' : '' }}"
                        class="mt-1"
                        placeholder="{{ __('assistant_knowledge.keywords_placeholder') }}"
                    />
                    <p id="knowledge-entry-keywords-help" class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('assistant_knowledge.keywords_help') }}</p>
                    <x-ui.forms.input-error for="entryKeywords" class="mt-1" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><x-ui.forms.toggle-button model="entryActive" :label="__('assistant_knowledge.entry_active_label')" /></div>
                    <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200/60 dark:bg-amber-950/20 dark:ring-amber-800/40">
                        <x-ui.forms.toggle-button
                            id="knowledge-entry-baseline"
                            model="entryBaseline"
                            :disabled="$baselineCapacityReached"
                            :label="__('assistant_knowledge.entry_baseline_label')"
                            :aria-invalid="$errors->has('entryBaseline') ? 'true' : 'false'"
                            aria-describedby="knowledge-entry-baseline-help{{ $errors->has('entryBaseline') ? ' entrybaseline-error' : '' }}"
                        />
                        <p id="knowledge-entry-baseline-help" class="mt-1.5 text-xs leading-5 text-amber-900 dark:text-amber-200">
                            {{ $baselineAssignedCount }}/{{ $baselineLimit }} {{ __('assistant_knowledge.baseline_slots_used') }}
                            @if ($baselineCapacityReached)
                                {{ __('assistant_knowledge.baseline_capacity_hint') }}
                            @endif
                        </p>
                        <x-ui.forms.input-error for="entryBaseline" class="mt-1" />
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
                <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                {{ __('assistant_knowledge.unsaved_changes') }}
            </span>
            <x-ui.buttons.button-basic mode="secondary" type="button" class="min-h-11" wire:click="$set('entryEditorOpen', false)">{{ __('assistant_knowledge.cancel') }}</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" type="submit" form="assistant-knowledge-entry-form" class="ml-2 min-h-11" wire:loading.attr="disabled" wire:target="saveEntry">{{ __('assistant_knowledge.save_information') }}</x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>
</div>
