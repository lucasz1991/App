<div data-testid="person-preview-host">
    <x-modal
        id="person-preview-modal"
        wire:model="isOpen"
        maxWidth="md"
        aria-labelledby="person-preview-title"
    >
        @if ($person)
            @php
                $canStartChat = $person->isActive() && ! $person->is(auth()->user());
                $position = $person->profile?->position ?: __('app.position_not_set');
            @endphp

            <article
                class="relative overflow-hidden"
                wire:key="person-preview-{{ $person->id }}"
                data-testid="person-preview-modal"
            >
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 h-32 bg-gradient-to-br from-rt-red/15 via-rt-red/5 to-transparent dark:from-rt-dark-accent/20"
                    aria-hidden="true"
                ></div>

                <header class="relative flex items-start justify-between gap-4 px-5 pb-4 pt-5 sm:px-6 sm:pt-6">
                    <div class="flex min-w-0 items-center gap-4">
                        <x-chat.avatar
                            :src="$person->profile_photo_url"
                            :name="$person->name"
                            size="lg"
                        />

                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red dark:text-rt-dark-accent">
                                {{ __('app.person_preview') }}
                            </p>
                            <h2
                                id="person-preview-title"
                                class="mt-1 truncate text-xl font-extrabold tracking-[-0.035em] text-rt-text dark:text-rt-dark-text"
                            >
                                {{ $person->name }}
                            </h2>
                            <p class="mt-1 truncate text-sm font-medium text-rt-muted dark:text-rt-dark-muted">
                                {{ $position }}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="close"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-muted transition-all duration-200 hover:bg-rt-nav-hover hover:text-rt-text active:scale-[0.96] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text"
                        aria-label="{{ __('app.close') }}"
                    >
                        <i class="far fa-times" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="relative border-t border-rt-border/70 px-4 py-4 dark:border-rt-dark-border/70 sm:px-6 sm:py-5">
                    <div class="grid grid-cols-4 gap-2" aria-label="{{ __('app.communication_actions') }}">
                        <button
                            type="button"
                            wire:click="startChat"
                            wire:loading.attr="disabled"
                            wire:target="startChat"
                            @disabled(! $canStartChat)
                            class="group flex min-w-0 flex-col items-center gap-2 rounded-xl px-1.5 py-3 text-center text-rt-muted transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 disabled:cursor-not-allowed disabled:opacity-40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.chat') }}"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-surface-muted text-base shadow-rt-xs ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-comment-dots" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.chat') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="previewCommunication('voice')"
                            class="group flex min-w-0 flex-col items-center gap-2 rounded-xl px-1.5 py-3 text-center text-rt-muted transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.voice_call') }}"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-surface-muted text-base shadow-rt-xs ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-phone-alt" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.voice_call') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="previewCommunication('video')"
                            class="group flex min-w-0 flex-col items-center gap-2 rounded-xl px-1.5 py-3 text-center text-rt-muted transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.video_call') }}"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-surface-muted text-base shadow-rt-xs ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-video" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.video_call') }}</span>
                        </button>

                        <a
                            href="mailto:{{ $person->email }}"
                            class="group flex min-w-0 flex-col items-center gap-2 rounded-xl px-1.5 py-3 text-center text-rt-muted transition-all duration-200 hover:-translate-y-0.5 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.send_email') }}"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-surface-muted text-base shadow-rt-xs ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-envelope" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.email') }}</span>
                        </a>
                    </div>
                </div>
            </article>
        @endif
    </x-modal>
</div>
