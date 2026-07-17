<div class="relative" wire:poll.60s="loadInbox">
    @php
        $messageRoute = in_array(auth()->user()?->role, ['admin', 'staff'], true)
            ? route('admin.messages')
            : route('messages');
    @endphp
    <x-dropdown align="right" width="w-96">
        {{-- Trigger --}}
        <x-slot name="trigger">
            <x-topbar.control-button
                    title="{{ __('app.messages') }}"
                    aria-haspopup="true"
                    class="relative w-9 px-0">
                <i class="far fa-envelope text-base" aria-hidden="true"></i>

                @if ($unreadMessagesCount >= 1)
                    <span class="absolute -right-1.5 -top-1.5 rounded-full bg-rt-red px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                        {{ $unreadMessagesCount }}
                    </span>
                @endif
            </x-topbar.control-button>
        </x-slot>

        {{-- Content --}}
        <x-slot name="content">
            <div class="max-w-[calc(100vw-2rem)] divide-y divide-rt-border bg-rt-surface text-[0.8125rem]/5 text-rt-text dark:divide-rt-dark-border dark:bg-rt-dark-surface dark:text-white">
                @forelse ($receivedMessages as $message)
                    @php
                        $isAdminSender = optional($message->sender)->role === 'admin';
                        $senderName    = $isAdminSender ? config('app.name') : ($message->sender->name ?? __('app.unknown'));
                        $senderAvatar  = $isAdminSender
                            ? asset('rt-brand/rt-logo.svg')
                            : ($message->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));
                        $isUnread      = (int) $message->status === 1;
                    @endphp

                    <button
                        type="button"
                        class="flex w-full items-center gap-3 p-3 text-left transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted {{ $isUnread ? 'bg-rt-accent-soft dark:bg-rt-dark-accent-soft' : '' }}"
                        x-on:click="$wire.showMessage({{ $message->id }})"
                    >
                        <img src="{{ $senderAvatar }}" class="h-8 w-8 rounded-full object-cover" alt="">
                        <div class="min-w-0 flex-auto">
                            <div class="flex items-center gap-2">
                                <div class="truncate font-medium {{ $isUnread ? 'font-semibold' : '' }}">{{ $senderName }}</div>
                                <div class="text-[11px] text-rt-muted dark:text-white/70" title="{{ $message->created_at->format('d.m.Y H:i') }}">
                                    {{ $message->created_at->diffForHumans() }}
                                </div>
                            </div>
                            <div class="truncate text-rt-text dark:text-white {{ $isUnread ? 'font-medium' : '' }}">{{ $message->subject }}</div>
                            <div class="mt-0.5 flex items-center gap-2 text-rt-muted dark:text-white/80">
                                @if ($message->files_count > 0)
                                    <i class="far fa-paperclip shrink-0 text-rt-soft dark:text-white/60" aria-hidden="true"></i>
                                @endif
                                <span class="truncate">{{ \Illuminate\Support\Str::limit(strip_tags($message->message), 60) }}</span>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="p-4 text-center text-rt-muted dark:text-white/80">{{ __('app.no_messages') }}</div>
                @endforelse

                <div class="p-3">
                    <a href="{{ $messageRoute }}"
                       class="block rounded-lg px-4 py-2 text-center font-medium text-rt-text ring-1 ring-rt-border transition hover:bg-rt-surface-muted hover:text-rt-accent dark:text-white dark:ring-rt-dark-border dark:hover:bg-rt-dark-surface-muted dark:hover:text-white">
                        {{ __('app.view_all_messages') }}
                    </a>
                </div>
            </div>
        </x-slot>
    </x-dropdown>

    {{-- Anzeige-Modal --}}
    <x-ui.messages.message-show-modal
        model="showMessageModal"
        :message="$selectedMessage"
    />
</div>
