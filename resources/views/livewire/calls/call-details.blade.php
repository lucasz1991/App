<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :title="$room->name"
        :eyebrow="__('app.calls_history')"
        :description="__('app.calls_detail_intro')"
    >
        <x-slot:actions>
            <a
                href="{{ route('calls.index') }}"
                wire:navigate
                class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-bold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text"
            >
                <i class="far fa-arrow-left" aria-hidden="true"></i>
                {{ __('app.calls_back_to_history') }}
            </a>
        </x-slot:actions>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.45fr)]">
            <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <header class="border-b border-rt-border/60 px-5 py-4 dark:border-rt-dark-border/60 sm:px-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold',
                            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $room->isActive(),
                            'bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted' => ! $room->isActive(),
                        ])>
                            <span class="h-1.5 w-1.5 rounded-full {{ $room->isActive() ? 'bg-emerald-500' : 'bg-current' }}"></span>
                            {{ __('app.calls_status_'.$room->status) }}
                        </span>
                        <span class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                            {{ $room->created_at?->translatedFormat('D, d.m.Y H:i') }}
                            @if ($duration = $room->durationForHumans())
                                · {{ __('app.calls_duration') }} {{ $duration }}
                            @endif
                        </span>
                    </div>
                </header>

                @if ($room->callChat)
                    <livewire:calls.call-chat :room="$room" :read-only="true" :key="'call-history-chat-'.$room->id" />
                @endif
            </section>

            <div class="space-y-5">
                @if ($room->recording)
                    @php($recording = $room->recording)
                    <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                        <header class="border-b border-rt-border/60 px-5 py-3.5 dark:border-rt-dark-border/60">
                            <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.calls_recording') }}</h2>
                        </header>
                        <div class="space-y-3 px-5 py-4">
                            <div class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                <span @class([
                                    'h-2.5 w-2.5 rounded-full',
                                    'bg-red-500' => $recording->status->value === 'active',
                                    'bg-emerald-500' => $recording->status->value === 'complete',
                                    'bg-amber-400' => in_array($recording->status->value, ['requested', 'starting', 'ending'], true),
                                    'bg-rose-500' => in_array($recording->status->value, ['failed', 'aborted'], true),
                                    'bg-slate-400' => $recording->status->value === 'expired',
                                ])></span>
                                {{ __('app.calls_recording_status_'.$recording->status->value) }}
                            </div>

                            @if ($recording->expires_at && $recording->status->value !== 'expired')
                                <p class="text-xs text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.calls_recording_expires', ['date' => $recording->expires_at->translatedFormat('d.m.Y H:i')]) }}
                                </p>
                            @elseif ($recording->status->value === 'expired')
                                <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.calls_recording_expired') }}</p>
                            @endif

                            @if ((int) $room->owner_id === (int) auth()->id() && $recording->isPlayable())
                                <a
                                    href="{{ route('calls.recording.play', [$room, $recording]) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-accent px-4 text-sm font-bold text-white transition hover:bg-rt-accent-hover"
                                >
                                    <i class="far fa-play" aria-hidden="true"></i>
                                    {{ __('app.calls_recording_play') }}
                                </a>
                            @elseif ((int) $room->owner_id !== (int) auth()->id())
                                <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.calls_recording_owner_only') }}</p>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <header class="border-b border-rt-border/60 px-5 py-3.5 dark:border-rt-dark-border/60">
                        <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.calls_participants') }}</h2>
                    </header>
                    <ul class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                        @foreach ($room->participants as $participant)
                            <li class="flex items-center gap-3 px-5 py-3" wire:key="history-participant-{{ $participant->id }}">
                                <x-chat.avatar
                                    :src="$participant->user?->profile_photo_url"
                                    :name="$participant->user?->name ?? $participant->guest_name ?? '?'"
                                    size="sm"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                        {{ $participant->user?->name ?? $participant->guest_name ?? __('app.calls_unknown_participant') }}
                                    </span>
                                    <span class="block text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                        {{ __('app.calls_role_'.$participant->role) }}
                                        @if ($participant->joined_at)
                                            · {{ $participant->joined_at->format('H:i') }}
                                        @endif
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <header class="border-b border-rt-border/60 px-5 py-3.5 dark:border-rt-dark-border/60">
                        <h2 class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.calls_timeline') }}</h2>
                    </header>
                    <ol class="space-y-0 px-5 py-3">
                        @forelse ($room->events as $event)
                            <li class="relative border-l border-rt-border/70 py-2 pl-5 text-xs dark:border-rt-dark-border/70" wire:key="room-event-{{ $event->id }}">
                                <span class="absolute -left-1 top-3 h-2 w-2 rounded-full bg-rt-accent"></span>
                                <p class="font-semibold text-rt-text dark:text-rt-dark-text">
                                    {{ __('app.calls_event_'.$event->type) }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                    @if ($event->actor)
                                        {{ $event->actor->name }} ·
                                    @endif
                                    {{ $event->occurred_at?->translatedFormat('d.m.Y H:i:s') }}
                                </p>
                            </li>
                        @empty
                            <li class="py-5 text-center text-xs text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.calls_timeline_empty') }}
                            </li>
                        @endforelse
                    </ol>
                </section>
            </div>
        </div>
    </x-ui.page>
</div>
