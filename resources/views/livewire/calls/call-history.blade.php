<div class="relative rt-comm-history flex min-h-0 flex-1 flex-col overflow-hidden" wire:loading.class="cursor-wait">
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
                <button type="button" class="rt-floating-toolbar__button rt-comm-new-call" x-on:click="$dispatch('communication:open', { tab: 'chats', newChat: true })" aria-label="Kontakt für neuen Anruf wählen" title="Kontakt für neuen Anruf wählen">
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
                    <p class="mt-1 truncate text-xs text-rt-muted {{ $missed ? 'rt-comm-call-missed' : '' }}">
                        {{ $missed ? __('app.calls_not_connected') : ($outgoing ? __('app.calls_outgoing') : __('app.calls_incoming')) }}
                        @if ($duration) · {{ $duration }} @endif
                    </p>
                    <p class="mt-1 text-[11px] text-rt-muted">{{ $room->created_at?->translatedFormat('D, d.m.Y H:i') }}</p>
                </div>
                @if ($live && $room->mayJoin(auth()->user()))
                    <a href="{{ route('calls.window', $room) }}" class="rt-comm-icon text-emerald-600" aria-label="{{ __('app.calls_join_ongoing') }}" title="{{ __('app.calls_join_ongoing') }}">
                        <i class="far fa-phone-alt" aria-hidden="true"></i>
                    </a>
                @elseif (! $live && $historyAccess)
                    <a href="{{ route('calls.history', $room) }}" class="rt-comm-icon" aria-label="{{ __('app.calls_open_history') }}" title="{{ __('app.calls_open_history') }}">
                        <i class="far fa-history" aria-hidden="true"></i>
                    </a>
                @endif
            </article>
        @empty
            <p class="px-6 py-12 text-center text-sm text-rt-muted">{{ __('app.calls_history_empty') }}</p>
        @endforelse
        @if ($rooms->hasPages())
            <div class="px-3 py-2"><x-tables.pagination :paginator="$rooms" :compact="true" label="Anrufseiten" /></div>
        @endif
    </div>
</div>
