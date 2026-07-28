<div class="relative space-y-5" data-autosave-scope>
    <x-ui.autosave-status
        event="note-inline-saved"
        target="savePendingNoteChanges"
        dirty-target="noteBodies"
    />

    {{-- Neue Bemerkung erfassen --}}
    <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
        <label for="noteBody" class="mb-2 block text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            {{ __('app.add_note') }}
        </label>

        <x-ui.forms.textarea
            id="noteBody"
            wire:model="noteBody"
            rows="3"
            placeholder="{{ __('app.note_placeholder') }}"
            class="block"
        />
        <x-input-error for="noteBody" class="mt-2" />

        <div class="mt-3 flex justify-end">
            <x-ui.buttons.button-basic
                :size="'sm'"
                :mode="'primary'"
                wire:click="addNote"
                wire:loading.attr="disabled"
                wire:target="addNote"
            >
                <i class="far fa-plus mr-2"></i>
                {{ __('app.save_note') }}
            </x-ui.buttons.button-basic>
        </div>
    </div>

    {{-- Liste der Bemerkungen (neueste zuerst) --}}
    @forelse ($notes as $note)
        <article
            wire:key="user-note-{{ $note->id }}"
            class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            x-data="{
                editing: false,
                saving: false,
                open() {
                    this.editing = true;
                    this.$nextTick(() => this.$refs.editor?.focus());
                },
                async commit() {
                    if (! this.editing || this.saving) return;
                    this.saving = true;

                    try {
                        const saved = await this.$wire.saveNote({{ $note->id }});
                        if (saved) this.editing = false;
                    } finally {
                        this.saving = false;
                    }
                },
                async cancel() {
                    if (this.saving) return;
                    this.saving = true;

                    try {
                        await this.$wire.cancelNoteEdit({{ $note->id }});
                        this.editing = false;
                    } finally {
                        this.saving = false;
                    }
                },
            }"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2 text-sm">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                        <i class="far fa-user text-xs"></i>
                    </span>
                    <div>
                        <div class="font-semibold text-slate-800 dark:text-slate-200">
                            {{ $note->author?->name ?? '-' }}
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $note->created_at->format('d.m.Y H:i') }}
                        </div>
                    </div>
                </div>

                @if ($note->author_id === auth()->id() || auth()->user()->isAdmin())
                    <button
                        type="button"
                        x-on:click.prevent='$dispatch("rt-confirm", {
                            title: @js(__("app.delete")),
                            message: @js(__("app.note_delete_confirm")),
                            variant: "destructive",
                            confirmLabel: @js(__("app.delete")),
                            action: () => $wire.deleteNote({{ $note->id }})
                        })'
                        class="inline-flex items-center rounded-lg border border-transparent px-2 py-1 text-xs text-red-600 transition-colors duration-300 ease-rt-spring hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-500/10"
                        title="{{ __('app.delete') }}"
                    >
                        <i class="far fa-trash-alt mr-1"></i>
                        {{ __('app.delete') }}
                    </button>
                @endif
            </div>

            @if ($note->author_id === auth()->id() || auth()->user()->isAdmin())
                <button
                    x-show.important="! editing"
                    type="button"
                    x-on:dblclick.prevent="open()"
                    x-on:keydown.enter.prevent="open()"
                    class="group/note-edit mt-3 flex w-full items-start gap-2 rounded-lg px-2 py-2 text-left text-sm text-slate-700 transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:text-slate-300 dark:hover:bg-rt-dark-surface-muted"
                    title="{{ __('app.double_click_to_edit') }}"
                    aria-label="{{ __('app.double_click_to_edit') }}"
                >
                    <span class="min-w-0 flex-1 whitespace-pre-line break-words">{{ $note->body }}</span>
                    <i class="far fa-pen mt-1 shrink-0 text-[9px] text-rt-soft opacity-0 transition group-hover/note-edit:opacity-100 group-focus-visible/note-edit:opacity-100 dark:text-rt-dark-soft" aria-hidden="true"></i>
                </button>

                <div x-cloak x-show.important="editing" class="mt-3">
                    <textarea
                        x-ref="editor"
                        wire:model="noteBodies.{{ $note->id }}"
                        rows="4"
                        x-on:blur="commit()"
                        x-on:keydown.escape.prevent.stop="cancel()"
                        x-on:keydown.ctrl.enter.prevent="$el.blur()"
                        x-bind:disabled="saving"
                        class="rt-ui-control min-h-28 w-full resize-y rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition duration-200 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:focus:ring-rt-dark-accent/20"
                    ></textarea>
                    <x-input-error for="noteBodies.{{ $note->id }}" />
                </div>
            @else
                <div class="mt-3 whitespace-pre-line break-words text-sm text-slate-700 dark:text-slate-300">{{ $note->body }}</div>
            @endif
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 p-6 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40 dark:text-rt-dark-muted">
            <i class="fad fa-sticky-note mb-2 block text-2xl"></i>
            {{ __('app.no_notes') }}
        </div>
    @endforelse
</div>
