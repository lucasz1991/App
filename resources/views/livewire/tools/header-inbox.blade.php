{{--
    Gemeinsamer Posteingang in der Topbar: EIN Icon-Button mit dem
    zusammengezaehlten Ungelesen-Zaehler (Chats + Nachrichten). Das Dropdown
    darunter zeigt beide Bereiche in je einem Abschnitt.
--}}
<div class="relative flex items-center" wire:poll.60s="loadInbox">
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
                dropdown-classes="!rounded-xl !shadow-rt-md"
                content-classes="!rounded-xl !border-rt-border/60 bg-rt-surface text-rt-text dark:!border-rt-dark-border/60 dark:bg-rt-dark-surface dark:text-white">
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
                class="max-w-[calc(100vw-2rem)] bg-rt-surface text-[0.8125rem]/5 text-rt-text dark:bg-rt-dark-surface dark:text-white"
            >
                {{-- Tab-Leiste: Chats | Nachrichten, mit Ungelesen-Zaehlern. --}}
                <div class="grid grid-cols-2 gap-1 border-b border-rt-border/70 p-1.5 dark:border-rt-dark-border/70" role="tablist" data-inbox-tabs>
                    @foreach ([
                        'chats' => ['label' => __('app.chats'), 'count' => $unreadChatMessagesCount],
                        'messages' => ['label' => __('app.messages'), 'count' => $unreadMessagesCount],
                    ] as $tabKey => $tab)
                        <button
                            type="button"
                            role="tab"
                            @click.stop="inboxTab = @js($tabKey)"
                            :aria-selected="(inboxTab === @js($tabKey)).toString()"
                            :class="inboxTab === @js($tabKey)
                                ? 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent'
                                : 'text-rt-muted hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white'"
                            class="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold outline-none transition focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent/35"
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
                <div class="divide-y divide-rt-border dark:divide-rt-dark-border">
                    @forelse ($recentChats as $chat)
                        @php
                            $chatUnread = $unreadPerChat[$chat->id] ?? 0;
                            $chatName = $chat->displayNameFor($viewer);
                            $chatAvatar = $chat->avatarUrlFor($viewer) ?: asset('rt-brand/rt-logo.svg');
                            $lastMessage = $chat->latestMessage;
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

                        <a
                            href="{{ route('chat', ['chat' => $chat->id]) }}"
                            wire:navigate
                            class="flex w-full items-center gap-3 p-3 text-left transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted {{ $chatUnread > 0 ? 'bg-rt-accent-soft dark:bg-rt-dark-accent-soft' : '' }}"
                        >
                            @if ($chat->isGroup())
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                    <i class="far fa-users text-xs" aria-hidden="true"></i>
                                </span>
                            @else
                                <img src="{{ $chatAvatar }}" class="h-8 w-8 shrink-0 rounded-full object-cover" alt="">
                            @endif

                            <span class="min-w-0 flex-auto">
                                <span class="flex items-center gap-2">
                                    <span class="truncate font-medium {{ $chatUnread > 0 ? 'font-semibold' : '' }}">{{ $chatName }}</span>
                                    @if ($lastMessage)
                                        <span class="shrink-0 text-[11px] text-rt-muted dark:text-white/70" title="{{ $lastMessage->created_at->format('d.m.Y H:i') }}">
                                            {{ $lastMessage->created_at->diffForHumans() }}
                                        </span>
                                    @endif
                                </span>
                                <span class="mt-0.5 block truncate text-rt-muted dark:text-white/80">
                                    {{ $preview ?: '—' }}
                                </span>
                            </span>

                            @if ($chatUnread > 0)
                                <span class="ml-1 shrink-0 rounded-full bg-rt-red px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                    {{ $chatUnread > 99 ? '99+' : $chatUnread }}
                                </span>
                            @endif
                        </a>
                    @empty
                        <div class="p-4 text-center text-rt-muted dark:text-white/80">{{ __('app.no_chats') }}</div>
                    @endforelse
                </div>

                <div class="border-t border-rt-border px-3 py-2 dark:border-rt-dark-border">
                    <a href="{{ route('chat') }}"
                       wire:navigate
                       class="block rounded-lg px-4 py-2 text-center font-medium text-rt-text shadow-rt-xs ring-1 ring-rt-border transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted hover:text-rt-accent active:scale-[0.98] dark:text-white dark:ring-rt-dark-border dark:hover:bg-rt-dark-surface-muted dark:hover:text-white">
                        {{ __('app.view_all_chats') }}
                    </a>
                </div>
                </div>

                {{-- ---------------- Nachrichten ---------------- --}}
                <div x-show.important="inboxTab === 'messages'" x-cloak data-inbox-panel="messages">

                <div class="divide-y divide-rt-border dark:divide-rt-dark-border">
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
                            class="flex w-full items-center gap-3 p-3 text-left transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted {{ $isUnread ? 'bg-rt-accent-soft dark:bg-rt-dark-accent-soft' : '' }}"
                            wire:click="$dispatch('message-viewer:open', { messageId: {{ $message->id }} })"
                        >
                            <img src="{{ $senderAvatar }}" class="h-8 w-8 shrink-0 rounded-full object-cover" alt="">
                            <div class="min-w-0 flex-auto">
                                <div class="flex items-center gap-2">
                                    <div class="truncate font-medium {{ $isUnread ? 'font-semibold' : '' }}">{{ $senderName }}</div>
                                    <div class="shrink-0 text-[11px] text-rt-muted dark:text-white/70" title="{{ $message->created_at->format('d.m.Y H:i') }}">
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
                </div>

                <div class="border-t border-rt-border p-3 dark:border-rt-dark-border">
                    <a href="{{ $messageRoute }}"
                       wire:navigate
                       class="block rounded-lg px-4 py-2 text-center font-medium text-rt-text shadow-rt-xs ring-1 ring-rt-border transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted hover:text-rt-accent active:scale-[0.98] dark:text-white dark:ring-rt-dark-border dark:hover:bg-rt-dark-surface-muted dark:hover:text-white">
                        {{ __('app.view_all_messages') }}
                    </a>
                </div>
                </div>
            </div>
        </x-slot>
    </x-dropdown>
</div>
