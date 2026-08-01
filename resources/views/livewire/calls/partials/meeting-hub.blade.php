<div class="mb-5 grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(22rem,1.2fr)]" data-anim="fade-up">
    @if ($canStart)
        <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <header class="flex items-start gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    <i class="fad fa-users-medical" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.meetings_create') }}</h2>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.meetings_create_hint') }}</p>
                </div>
            </header>

            <form wire:submit="createMeeting" class="space-y-4 px-5 py-5">
                <div>
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
                </div>

                <label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-rt-muted dark:text-rt-dark-muted">
                    <x-ui.forms.checkbox wire:model="video" />
                    {{ __('app.meetings_with_video') }}
                </label>

                <x-ui.buttons.button-basic
                    type="submit"
                    mode="primary"
                    class="w-full justify-center"
                    wire:loading.attr="disabled"
                    wire:target="createMeeting"
                >
                    <i class="far fa-video" aria-hidden="true"></i>
                    {{ __('app.meetings_start') }}
                </x-ui.buttons.button-basic>
            </form>
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 {{ $canStart ? '' : 'xl:col-span-2' }}">
        <header class="flex items-center gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <i class="fad fa-signal-stream" aria-hidden="true"></i>
            </span>
            <h2 class="flex-1 text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.meetings_live') }}</h2>
            <span class="rounded-full bg-rt-surface px-2.5 py-1 text-[11px] font-bold text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                {{ $liveMeetings->count() }}
            </span>
        </header>

        @if ($liveMeetings->isEmpty())
            <p class="px-5 py-10 text-center text-xs text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.meetings_live_empty') }}
            </p>
        @else
            <ul class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                @foreach ($liveMeetings as $liveRoom)
                    @php
                        $joined = $liveRoom->participants->filter->isConnected()->count();
                        $currentParticipant = $liveRoom->participants->firstWhere('user_id', $currentUserId);
                        $mayRequestJoin = ! $currentParticipant?->isRemoved();
                    @endphp
                    <li class="flex items-center gap-3 px-4 py-3.5 sm:px-5" wire:key="live-meeting-{{ $liveRoom->id }}">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <i @class(['far', 'fa-video' => $liveRoom->startsWithVideo(), 'fa-phone' => ! $liveRoom->startsWithVideo()]) aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ $liveRoom->name }}</p>
                            <p class="mt-0.5 truncate text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.meetings_host') }}: {{ $liveRoom->owner?->name ?? '–' }}
                                · {{ trans_choice('app.meetings_participants_count', $joined, ['count' => $joined]) }}
                            </p>
                        </div>
                        @if ($mayRequestJoin)
                            <button
                                type="button"
                                wire:click="join({{ $liveRoom->id }})"
                                wire:loading.attr="disabled"
                                class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-emerald-500/10 px-3 text-xs font-bold text-emerald-600 transition hover:bg-emerald-500/20 dark:text-emerald-400"
                            >
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                {{ __('app.calls_join') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
