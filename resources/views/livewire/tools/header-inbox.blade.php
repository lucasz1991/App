{{--
    Die Topbar zeigt nur normale Nachrichten. Chat-Benachrichtigungen bleiben
    im Polling-Fallback aktiv; ihre Ansicht und ihr Badge gehoeren zum Kommunikationsmodul.
--}}
<div
    class="relative flex items-center"
    wire:poll.60s="loadInbox"
    data-app-badge-count="{{ $totalUnreadCount }}"
>
    @php
        $viewer = auth()->user();
        $messageRoute = $viewer?->usesAdminLayout()
            ? route('admin.messages')
            : route('messages');
    @endphp

    <x-dropdown align="right" width="w-96"
                dropdown-id="topbar-inbox"
                layer-group="topbar"
                wire:key="topbar-inbox-dropdown"
                dropdown-classes="rt-inbox-dropdown !rounded-[1.2rem]"
                content-classes="rt-inbox-panel !rounded-[1.2rem] bg-rt-surface p-1.5 text-rt-text dark:bg-rt-dark-surface dark:text-white">
        {{-- Trigger und Badge zaehlen ausschliesslich normale Nachrichten. --}}
        <x-slot name="trigger">
            <x-topbar.control-button
                    title="{{ __('app.messages') }}"
                    aria-label="{{ __('app.messages') }}"
                    aria-haspopup="true"
                    data-inbox-trigger="true"
                    data-inbox-total="{{ $totalUnreadCount }}"
                    class="relative h-10 w-10 px-0">
                <i class="far fa-envelope text-base" aria-hidden="true"></i>

                @if ($totalUnreadCount >= 1)
                    <span class="absolute -right-1.5 -top-1.5 rounded-full bg-rt-red px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                        {{ $totalUnreadCount > 99 ? '99+' : $totalUnreadCount }}
                    </span>
                @endif
            </x-topbar.control-button>
        </x-slot>

        <x-slot name="content">
            <div
                class="max-w-[calc(100vw-2rem)] text-[0.8125rem]/5"
                data-rt-inbox-premium-list
                data-inbox-panel="messages"
            >
                <div class="rt-chat-stacked-list rt-chat-stacked-list--inbox mt-1.5 space-y-1">
                    @forelse ($receivedMessages as $message)
                        @php
                            $isAdminSender = optional($message->sender)->role === 'admin';
                            $senderName    = $isAdminSender ? config('app.name') : ($message->sender->name ?? __('app.unknown'));
                            $senderAvatar  = $isAdminSender
                                ? asset('rt-brand/rt-logo.svg')
                                : ($message->sender?->profile_photo_url ?? asset('rt-brand/rt-logo.svg'));
                            $isUnread      = (int) $message->status === 1;
                        @endphp

                        <x-chat.stacked-list-item
                            :unread="$isUnread"
                            compact
                            wire:click="$dispatch('message-viewer:open', { messageId: {{ $message->id }} })"
                        >
                            <x-slot:avatar>
                                <x-chat.avatar
                                    :src="$senderAvatar"
                                    :name="$senderName"
                                    size="xs"
                                    decorative
                                />
                            </x-slot:avatar>
                            <x-slot:title>{{ $senderName }}</x-slot:title>
                            <x-slot:context>{{ __('app.message') }}</x-slot:context>
                            <x-slot:meta>
                                @if ($message->files_count > 0)
                                    <i class="far fa-paperclip" aria-hidden="true"></i>
                                @else
                                    <i class="far fa-envelope" aria-hidden="true"></i>
                                @endif
                                <span class="rt-chat-stacked-item__meta-strong">{{ $message->subject }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ \Illuminate\Support\Str::limit(strip_tags($message->message), 60) }}</span>
                            </x-slot:meta>
                            @if ($isUnread)
                                <x-slot:status>{{ __('app.unread') }}</x-slot:status>
                            @else
                                <x-slot:time>
                                    <span title="{{ $message->created_at->format('d.m.Y H:i') }}">
                                        {{ $message->created_at->diffForHumans() }}
                                    </span>
                                </x-slot:time>
                            @endif
                        </x-chat.stacked-list-item>
                    @empty
                        <div class="p-4 text-center text-rt-muted dark:text-white/80">{{ __('app.no_messages') }}</div>
                    @endforelse
                </div>

                <div class="rt-inbox-footer px-1 pb-1 pt-1.5">
                    <a href="{{ $messageRoute }}"
                       wire:navigate
                       class="rt-inbox-view-all group flex min-h-10 items-center justify-between rounded-xl px-3.5 text-sm font-bold">
                        <span>{{ __('app.view_all_messages') }}</span>
                        <span class="rt-inbox-view-all__icon" aria-hidden="true"><i class="far fa-arrow-right"></i></span>
                    </a>
                </div>
            </div>
        </x-slot>
    </x-dropdown>
</div>
