<div @class(['relative', 'rt-comm-history flex min-h-0 flex-1 flex-col overflow-hidden' => $embedded]) wire:loading.class="cursor-wait">
    @if ($embedded)
        <x-ui.floating.toolbar label="Anrufe suchen und filtern" search>
            <x-ui.forms.search context="floating" id="communication-call-search" wire:model.live.debounce.250ms="search" placeholder="Anrufe suchen …" aria-label="Anrufe durchsuchen" />
            <x-slot:actions>
                <x-ui.dropdown.anchor-dropdown align="right" width="48" :offset="6" dropdown-id="communication-call-filter" layer-group="communication-controls" content-label="Anrufe filtern">
                    <x-slot:trigger>
                        <button type="button" class="rt-floating-toolbar__button" aria-label="Anrufe filtern" title="Anrufe filtern" aria-haspopup="menu">
                            <i class="far fa-sliders-h" aria-hidden="true"></i>
                        </button>
                    </x-slot:trigger>
                    <x-slot:content>
                        @foreach ([
                            'all' => __('app.calls_filter_all'),
                            'active' => __('app.calls_filter_active'),
                            'missed' => __('app.calls_filter_missed'),
                            'meetings' => __('app.calls_filter_meetings'),
                            'recordings' => __('app.calls_filter_recordings'),
                        ] as $key => $label)
                            <x-ui.dropdown.dropdown-link role="menuitemradio" aria-checked="{{ $filter === $key ? 'true' : 'false' }}" wire:click="setFilter('{{ $key }}')" x-on:click="close()">
                                <i class="far fa-check" style="visibility: {{ $filter === $key ? 'visible' : 'hidden' }}" aria-hidden="true"></i>
                                {{ $label }}
                            </x-ui.dropdown.dropdown-link>
                        @endforeach
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
                @if ($canStart)
                    <button type="button" class="rt-floating-toolbar__button" x-on:click="$dispatch('communication:open', { tab: 'chats', newChat: true })" aria-label="Kontakt für neuen Anruf wählen" title="Kontakt für neuen Anruf wählen">
                        <i class="far fa-plus" aria-hidden="true"></i>
                    </button>
                @endif
            </x-slot:actions>
        </x-ui.floating.toolbar>
        <div class="rt-comm-list min-h-0 flex-1 overflow-y-auto" data-communication-call-list>
            @forelse ($rooms as $room)
                @php
                    $live = $room->isActive();
                    $currentParticipant = $room->participants->firstWhere('user_id', $currentUserId);
                    $invitation = $room->invitations->first();
                    $missed = in_array($invitation?->status, ['missed', 'declined', 'expired'], true) || $currentParticipant?->isRemoved();
                    $outgoing = (int) $room->owner_id === (int) $currentUserId;
                    $duration = $room->durationForHumans();
                    $historyAccess = $room->callChat?->participants?->contains(fn ($participant) => (int) $participant->id === (int) $currentUserId) ?? false;
                @endphp
                <article class="rt-comm-card flex min-w-0 items-center gap-3" wire:key="embedded-room-{{ $room->id }}">
                    <span @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-full', 'bg-rose-50 text-rose-600' => $missed, 'bg-emerald-50 text-emerald-600' => ! $missed && $live, 'bg-slate-100 text-slate-500' => ! $missed && ! $live])>
                        <i @class(['far', 'fa-video' => $room->startsWithVideo(), 'fa-phone' => ! $room->startsWithVideo()]) aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ $room->name }}</p>
                        <p class="mt-1 truncate text-xs text-rt-muted">
                            {{ $missed ? __('app.calls_not_connected') : ($outgoing ? __('app.calls_outgoing') : __('app.calls_incoming')) }}
                            @if ($duration) · {{ $duration }} @endif
                        </p>
                        <p class="mt-1 text-[11px] text-rt-muted">{{ $room->created_at?->translatedFormat('D, d.m.Y H:i') }}</p>
                    </div>
                    @if ($live && $room->mayJoin(auth()->user()))
                        <a href="{{ route('calls.window', $room) }}" class="rt-comm-icon text-emerald-600" aria-label="{{ __('app.calls_join_ongoing') }}" title="{{ __('app.calls_join_ongoing') }}">
                            <i class="far fa-phone-arrow-up-right" aria-hidden="true"></i>
                        </a>
                    @elseif (! $live && $historyAccess)
                        <a href="{{ route('calls.history', $room) }}" class="rt-comm-icon" aria-label="{{ __('app.calls_open_history') }}" title="{{ __('app.calls_open_history') }}">
                            <i class="far fa-clock-rotate-left" aria-hidden="true"></i>
                        </a>
                    @endif
                </article>
            @empty
                <p class="px-6 py-12 text-center text-sm text-rt-muted">{{ __('app.calls_history_empty') }}</p>
            @endforelse
            @if ($rooms->hasPages())
                <div class="px-3 py-2">{{ $rooms->links() }}</div>
            @endif
        </div>
    @else
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
                            $currentInvitation = $room->invitations->first();
                            $currentParticipant = $room->participants->firstWhere('user_id', $currentUserId);
                            $missed = in_array($currentInvitation?->status, ['missed', 'declined', 'expired'], true)
                                || $currentParticipant?->isRemoved();
                            $joinable = $live && $room->mayJoin(auth()->user());
                            $hasHistoryAccess = $room->callChat?->participants
                                ?->contains(fn ($participant) => (int) $participant->id === (int) $currentUserId) ?? false;
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

                            @if ($joinable)
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
                            @elseif (! $live && $hasHistoryAccess)
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
    @endif
</div>
