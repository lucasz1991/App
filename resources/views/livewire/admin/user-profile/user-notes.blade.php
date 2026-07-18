<div class="space-y-5">
    {{-- Neue Bemerkung erfassen --}}
    <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
        <label for="noteBody" class="mb-2 block text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            {{ __('app.add_note') }}
        </label>

        <textarea
            id="noteBody"
            wire:model="noteBody"
            rows="3"
            placeholder="{{ __('app.note_placeholder') }}"
            class="block w-full rounded-lg border-rt-border bg-rt-surface text-sm shadow-rt-xs transition-all duration-300 ease-rt-spring focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:bg-rt-dark-surface dark:border-rt-dark-border dark:text-rt-dark-text dark:placeholder-rt-dark-soft"
        ></textarea>
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
                        wire:click="deleteNote({{ $note->id }})"
                        wire:confirm="{{ __('app.note_delete_confirm') }}"
                        class="inline-flex items-center rounded-lg border border-transparent px-2 py-1 text-xs text-red-600 transition-colors duration-300 ease-rt-spring hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-500/10"
                        title="{{ __('app.delete') }}"
                    >
                        <i class="far fa-trash-alt mr-1"></i>
                        {{ __('app.delete') }}
                    </button>
                @endif
            </div>

            <div class="mt-3 whitespace-pre-line break-words text-sm text-slate-700 dark:text-slate-300">{{ $note->body }}</div>
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 p-6 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40 dark:text-rt-dark-muted">
            <i class="fad fa-sticky-note mb-2 block text-2xl"></i>
            {{ __('app.no_notes') }}
        </div>
    @endforelse
</div>
