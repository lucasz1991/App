<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :title="__('app.calls_history')"
        :eyebrow="__('app.communication')"
        :description="__('app.calls_history_intro')"
    >
        @include('livewire.calls.partials.meeting-hub')

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ([
                    'all' => __('app.calls_filter_all'),
                    'active' => __('app.calls_filter_active'),
                    'missed' => __('app.calls_filter_missed'),
                    'meetings' => __('app.calls_filter_meetings'),
                    'recordings' => __('app.calls_filter_recordings'),
                ] as $key => $label)
                    <button
                        type="button"
                        wire:click="setFilter('{{ $key }}')"
                        @class([
                            'inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors',
                            'bg-rt-accent text-white shadow-rt-xs' => $filter === $key,
                            'bg-rt-surface text-rt-muted ring-1 ring-rt-border/60 hover:text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60 dark:hover:text-rt-dark-text' => $filter !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </x-slot:actions>

        @if ($rooms->isEmpty())
            <div class="rounded-2xl bg-rt-surface p-10 text-center shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
                <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    <i class="fad fa-phone-slash text-xl" aria-hidden="true"></i>
                </span>
                <p class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.calls_history_empty') }}</p>
                <p class="mx-auto mt-1 max-w-md text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.calls_history_empty_hint') }}</p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
                <ul class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    @foreach ($rooms as $room)
                        @php
                            $outgoing = (int) $room->owner_id === (int) $currentUserId;
                            $live = $room->isActive();
                            $duration = $room->durationForHumans();
                            $others = $room->participants
                                ->filter(fn ($p) => (int) $p->user_id !== (int) $currentUserId)
                                ->map(fn ($p) => $p->user?->name ?? $p->guest_name)
                                ->filter()
                                ->values();
                            $missed = ! ($room->connected_at ?? $room->started_at) && in_array($room->status, ['ended', 'cancelled'], true);
                        @endphp

                        <li class="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted sm:px-6" wire:key="room-{{ $room->id }}">
                            {{-- Richtung + Medium --}}
                            <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                'bg-rose-500/10 text-rose-600 dark:text-rose-400' => $missed,
                                'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => ! $missed && $live,
                                'bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted' => ! $missed && ! $live,
                            ])>
                                <i @class([
                                    'far',
                                    'fa-video' => $room->startsWithVideo(),
                                    'fa-phone' => ! $room->startsWithVideo(),
                                ]) aria-hidden="true"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2 truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">
                                    <i @class([
                                        'far text-[10px]',
                                        'fa-arrow-up-right text-sky-500' => $outgoing,
                                        'fa-arrow-down-left text-emerald-500' => ! $outgoing,
                                    ]) aria-hidden="true" title="{{ $outgoing ? __('app.calls_outgoing') : __('app.calls_incoming') }}"></i>
                                    {{ $room->name }}
                                </p>
                                <p class="mt-0.5 truncate text-[11px] text-rt-muted dark:text-rt-dark-muted">
                                    {{ $room->created_at?->translatedFormat('D, d.m.Y H:i') }}
                                    @if ($duration)
                                        · {{ __('app.calls_duration') }} {{ $duration }}
                                    @elseif ($missed)
                                        · {{ __('app.calls_not_connected') }}
                                    @endif
                                    @if ($others->isNotEmpty())
                                        · {{ $others->take(3)->join(', ') }}@if ($others->count() > 3) +{{ $others->count() - 3 }}@endif
                                    @endif
                                </p>
                            </div>

                            @if ($live)
                                <a
                                    href="{{ route('calls.window', $room) }}"
                                    class="inline-flex h-9 shrink-0 items-center gap-2 rounded-xl bg-emerald-500/10 px-3 text-xs font-bold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                                >
                                    <span class="relative flex h-2 w-2">
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-75"></span>
                                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                    </span>
                                    <span class="hidden sm:inline">{{ __('app.calls_join_ongoing') }}</span>
                                </a>
                            @elseif ($room->call_chat_id)
                                <a
                                    href="{{ route('calls.history', $room) }}"
                                    wire:navigate
                                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-rt-muted transition-colors hover:bg-rt-accent-soft hover:text-rt-accent dark:text-rt-dark-muted dark:hover:text-rt-dark-accent"
                                    title="{{ __('app.calls_open_history') }}"
                                    aria-label="{{ __('app.calls_open_history') }}"
                                >
                                    <i class="far fa-clock-rotate-left" aria-hidden="true"></i>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($rooms->hasPages())
                <div class="mt-4">{{ $rooms->links() }}</div>
            @endif
        @endif
    </x-ui.page>
</div>
