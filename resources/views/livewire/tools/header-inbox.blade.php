{{--
    Gemeinsamer Posteingang in der Topbar: EIN Icon-Button mit dem
    zusammengezaehlten Ungelesen-Zaehler (Chats + Nachrichten). Das Dropdown
    darunter zeigt beide Bereiche in je einem Abschnitt.
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
        {{-- Trigger: ein Icon, ein Zaehler fuer beides --}}
        <x-slot name="trigger">
            <x-topbar.control-button
                    title="{{ __('app.chat_and_messages') }}"
                    aria-label="{{ __('app.chat_and_messages') }}"
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
            @php
                // Vorausgewaehlt ist der Tab mit neuen Eintraegen; haben beide
                // welche, gewinnt der mit mehr Ungelesenen (Gleichstand: Chats).
                $inboxDefaultTab = $unreadMessagesCount > $unreadChatMessagesCount ? 'messages' : 'chats';
            @endphp
            <div
                x-data="{ inboxTab: @js($inboxDefaultTab) }"
                class="max-w-[calc(100vw-2rem)] text-[0.8125rem]/5"
                data-rt-inbox-premium-list
            >
                {{-- Tab-Leiste: Chats | Nachrichten, mit Ungelesen-Zaehlern. --}}
                <div class="rt-inbox-tabs grid grid-cols-2 gap-1 p-1" role="tablist" data-inbox-tabs>
                    @foreach ([
                        'chats' => ['label' => __('app.chats'), 'count' => $unreadChatMessagesCount],
                        'messages' => ['label' => __('app.messages'), 'count' => $unreadMessagesCount],
                    ] as $tabKey => $tab)
                        <button
                            type="button"
                            role="tab"
                            @click.stop="inboxTab = @js($tabKey)"
                            :aria-selected="(inboxTab === @js($tabKey)).toString()"
                            :class="inboxTab === @js($tabKey) ? 'is-active' : ''"
                            class="rt-inbox-tab inline-flex min-h-10 items-center justify-center gap-2 rounded-[0.72rem] px-3 text-sm font-bold outline-none"
                            data-inbox-tab="{{ $tabKey }}"
                        >
                            {{ $tab['label'] }}
                            @if ($tab['count'] >= 1)
                                <span class="rounded-full bg-rt-red px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                    {{ $tab['count'] > 99 ? '99+' : $tab['count'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- ---------------- Chats ---------------- --}}
                {{-- x-show.important: die Legacy-CSS traegt Display-Utilities mit
                     !important, ohne den Modifier liesse sich der Tab nie
                     ausblenden. --}}
                <div x-show.important="inboxTab === 'chats'" x-cloak data-inbox-panel="chats">
                <div class="rt-chat-stacked-list rt-chat-stacked-list--inbox mt-1.5 space-y-1">
                    @forelse ($recentChats as $chat)
                        @php
                            $chatUnread = $unreadPerChat[$chat->id] ?? 0;
                            $chatName = $chat->displayNameFor($viewer);
                            $chatAvatar = $chat->avatarUrlFor($viewer) ?: asset('rt-brand/rt-logo.svg');
                            $lastMessage = $chat->latestMessage;
                            $chatPerson = $chat->isGroup()
                                ? null
                                : $chat->participants->firstWhere('id', '!=', $viewer?->id);
                            $chatPersonIsOnline = $chatPerson?->isOnline() ?? false;
                            // Einmal-sichtbare Nachrichten werden in der Vorschau
                            // bewusst NICHT im Klartext angezeigt.
                            $preview = null;

                            if ($lastMessage) {
                                if ($lastMessage->view_once) {
                                    $preview = __('app.view_once_message');
                                } elseif ($lastMessage->message_type === 'voice') {
                                    $preview = __('app.voice_message');
                                } else {
                                    // body ist ein 'encrypted'-Cast. Ein Schluesselwechsel
                                    // oder eine aus einer anderen Umgebung kopierte
                                    // Datenbank wuerde sonst eine DecryptException werfen —
                                    // und zwar auf JEDER Seite, weil die Topbar ueberall
                                    // rendert. Deshalb bewusst ohne Log-Flut abfangen.
                                    $preview = rescue(
                                        fn () => \Illuminate\Support\Str::limit(
                                            strip_tags((string) $lastMessage->body), 48
                                        ),
                                        null,
                                        report: false,
                                    );
                                }
                            }
                        @endphp

                        <x-chat.stacked-list-item
                            :href="route('chat', ['chat' => $chat->id])"
                            wire:navigate
                            :unread="$chatUnread > 0"
                            compact
                        >
                            <x-slot:avatar>
                                <x-chat.avatar
                                    :src="$chatAvatar"
                                    :name="$chatName"
                                    size="xs"
                                    :signal="$chatPersonIsOnline"
                                    decorative
                                />
                            </x-slot:avatar>
                            <x-slot:title>{{ $chatName }}</x-slot:title>
                            <x-slot:context>
                                {{ $chat->isGroup() ? __('app.group_chat') : __('app.direct_chat') }}
                            </x-slot:context>
                            <x-slot:meta>
                                <i class="far fa-comment-dots" aria-hidden="true"></i>
                                <span>{{ $preview ?: __('app.no_messages_yet') }}</span>
                            </x-slot:meta>
                            @if ($lastMessage && $chatUnread === 0)
                                <x-slot:time>
                                    <span title="{{ $lastMessage->created_at->format('d.m.Y H:i') }}">
                                        {{ $lastMessage->created_at->diffForHumans() }}
                                    </span>
                                </x-slot:time>
                            @endif
                            @if ($chatUnread > 0)
                                <x-slot:badge>{{ $chatUnread > 99 ? '99+' : $chatUnread }}</x-slot:badge>
                            @endif
                        </x-chat.stacked-list-item>
                    @empty
                        <div class="p-4 text-center text-rt-muted dark:text-white/80">{{ __('app.no_chats') }}</div>
                    @endforelse
                </div>

                <div class="rt-inbox-footer px-1 pb-1 pt-1.5">
                    <a href="{{ route('chat') }}"
                       wire:navigate
                       class="rt-inbox-view-all group flex min-h-10 items-center justify-between rounded-xl px-3.5 text-sm font-bold">
                        <span>{{ __('app.view_all_chats') }}</span>
                        <span class="rt-inbox-view-all__icon" aria-hidden="true"><i class="far fa-arrow-right"></i></span>
                    </a>
                </div>
                </div>

                {{-- ---------------- Nachrichten ---------------- --}}
                <div x-show.important="inboxTab === 'messages'" x-cloak data-inbox-panel="messages">

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
            </div>
        </x-slot>
    </x-dropdown>
</div>
