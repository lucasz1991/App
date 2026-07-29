<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :title="__('app.meetings')"
        :eyebrow="__('app.communication')"
        :description="__('app.meetings_intro')"
    >
        @if ($canStart)
            {{-- Raum eroeffnen --}}
            <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
                <header class="flex items-start gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:px-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-users-medical" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.meetings_create') }}</h2>
                        <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.meetings_create_hint') }}</p>
                    </div>
                </header>

                <form wire:submit="createMeeting" class="grid gap-4 px-5 py-5 sm:grid-cols-[1fr_auto] sm:items-end sm:px-6">
                    <div class="min-w-0">
                        <x-ui.forms.label for="meeting-name" :value="__('app.meetings_name')" />
                        <x-ui.forms.input
                            id="meeting-name"
                            type="text"
                            wire:model="name"
                            class="mt-1 w-full"
                            :placeholder="__('app.meetings_name_placeholder')"
                            maxlength="80"
                        />
                        <x-input-error for="name" class="mt-1" />

                        <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                            <x-ui.forms.checkbox wire:model="video" />
                            {{ __('app.meetings_with_video') }}
                        </label>
                    </div>

                    <x-ui.buttons.button-basic
                        type="submit"
                        mode="primary"
                        class="w-full sm:w-auto"
                        wire:loading.attr="disabled"
                        wire:target="createMeeting"
                    >
                        <i class="far fa-video mr-2" aria-hidden="true"></i>
                        {{ __('app.meetings_start') }}
                    </x-ui.buttons.button-basic>
                </form>
            </section>
        @endif

        {{-- Laufende Meetings --}}
        <section class="mt-5 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
            <header class="flex items-center gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:px-6">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <i class="fad fa-signal-stream" aria-hidden="true"></i>
                </span>
                <h2 class="flex-1 text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.meetings_live') }}</h2>
                <span class="rounded-full bg-rt-surface px-2.5 py-1 text-[11px] font-bold text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                    {{ $live->count() }}
                </span>
            </header>

            @if ($live->isEmpty())
                <p class="px-5 py-8 text-center text-xs text-rt-muted dark:text-rt-dark-muted sm:px-6">
                    {{ __('app.meetings_live_empty') }}
                </p>
            @else
                <ul class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    @foreach ($live as $room)
                        @php $joined = $room->participants->where('connection', 'joined')->count(); @endphp
                        <li class="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted sm:px-6" wire:key="live-{{ $room->id }}">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                <i @class(['far', 'fa-video' => $room->startsWithVideo(), 'fa-phone' => ! $room->startsWithVideo()]) aria-hidden="true"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ $room->name }}</p>
                                <p class="mt-0.5 truncate text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.meetings_host') }}: {{ $room->owner?->name ?? '–' }}
                                    · {{ trans_choice('app.meetings_participants_count', $joined, ['count' => $joined]) }}
                                    @if ($room->started_at)
                                        · {{ __('app.meetings_since') }} {{ $room->started_at->format('H:i') }}
                                    @endif
                                </p>
                            </div>

                            <button
                                type="button"
                                wire:click="join({{ $room->id }})"
                                wire:loading.attr="disabled"
                                class="inline-flex h-9 shrink-0 items-center gap-2 rounded-xl bg-emerald-500/10 px-3 text-xs font-bold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                            >
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                </span>
                                {{ __('app.calls_join') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Vergangene Meetings --}}
        @if ($recent->isNotEmpty())
            <section class="mt-5 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
                <header class="border-b border-rt-border/60 bg-rt-surface-muted px-5 py-3.5 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:px-6">
                    <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.meetings_recent') }}</h2>
                </header>
                <ul class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    @foreach ($recent as $room)
                        <li class="flex items-center gap-3 px-4 py-3 sm:px-6" wire:key="past-{{ $room->id }}">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                <i class="far fa-clock-rotate-left text-xs" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] font-semibold text-rt-text dark:text-rt-dark-text">{{ $room->name }}</p>
                                <p class="truncate text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                    {{ $room->created_at?->translatedFormat('d.m.Y H:i') }}
                                    @if ($d = $room->durationForHumans())
                                        · {{ __('app.calls_duration') }} {{ $d }}
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </x-ui.page>
</div>
