<div class="relative" wire:loading.class="cursor-wait">
    @php
        $me = auth()->user();
    @endphp

    <x-ui.page :title="__('app.chat')" :eyebrow="__('app.personal_data')">

        {{-- Messenger-Karte --}}
        <div class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <div class="flex h-[calc(100vh-260px)] min-h-[480px]">

                {{-- ============================== LINKE SPALTE: Chat-Liste ============================== --}}
                <div class="flex w-80 shrink-0 flex-col border-r border-rt-border/60 dark:border-rt-dark-border/60">

                    {{-- Kopfzeile --}}
                    <div class="flex items-center justify-between border-b border-rt-border/60 px-4 py-3 dark:border-rt-dark-border/60">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-rt-text dark:text-rt-dark-text">
                            {{ __('app.chats') }}
                        </h2>
                        <button type="button"
                                wire:click="$set('showNewChat', true)"
                                title="{{ __('app.new_chat') }}"
                                class="flex h-8 w-8 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark active:scale-95 dark:bg-rt-red dark:text-white">
                            <i class="far fa-plus" aria-hidden="true"></i>
                            <span class="sr-only">{{ __('app.new_chat') }}</span>
                        </button>
                    </div>

                    {{-- Suche --}}
                    <div class="border-b border-rt-border/60 px-3 py-2.5 dark:border-rt-dark-border/60">
                        <label for="chat-search" class="sr-only">{{ __('app.search') }}</label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="far fa-search text-xs text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                            </div>
                            <x-ui.forms.input type="text"
                                              id="chat-search"
                                              wire:model.live.debounce.300ms="search"
                                              placeholder="{{ __('app.search') }}"
                                              autocomplete="off"
                                              class="rounded-full pl-9 text-sm" />
                        </div>
                    </div>

                    {{-- Chat-Liste --}}
                    <div class="flex-1 overflow-y-auto py-1">
                        @forelse ($chats as $chat)
                            @php
                                $isActive  = (int) $selectedChatId === (int) $chat->id;
                                $unread    = $chat->unreadCountFor($me);
                                $latest    = $chat->latestMessage;
                                $avatarUrl = $chat->avatarUrlFor($me);
                                $timeLabel = $latest
                                    ? ($latest->created_at->isToday() ? $latest->created_at->format('H:i') : $latest->created_at->format('d.m.'))
                                    : '';
                            @endphp

                            <button type="button"
                                    wire:key="chat-item-{{ $chat->id }}"
                                    wire:click="openChat({{ $chat->id }})"
                                    class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors duration-200 ease-rt-spring {{ $isActive ? 'bg-rt-accent-soft/60 dark:bg-rt-dark-accent-soft/40' : 'hover:bg-rt-surface-muted/70 dark:hover:bg-rt-dark-surface-muted/50' }}">

                                {{-- Avatar --}}
                                @if ($avatarUrl)
                                    <img src="{{ $avatarUrl }}"
                                         alt="{{ $chat->displayNameFor($me) }}"
                                         class="h-11 w-11 shrink-0 rounded-full object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                                @else
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-rt-accent-soft text-rt-accent ring-1 ring-rt-border/60 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-border/60">
                                        <i class="fad fa-users" aria-hidden="true"></i>
                                    </span>
                                @endif

                                {{-- Name + Vorschau --}}
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                        {{ $chat->displayNameFor($me) }}
                                    </span>
                                    <span class="block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                        @if ($latest)
                                            {{ (int) $latest->user_id === (int) $me->id ? __('app.you') . ': ' : '' }}{{ $latest->body }}
                                        @endif
                                    </span>
                                </span>

                                {{-- Zeit + Ungelesen-Badge --}}
                                <span class="flex shrink-0 flex-col items-end gap-1">
                                    <span class="text-[10px] text-rt-soft dark:text-rt-dark-soft">{{ $timeLabel }}</span>
                                    @if ($unread > 0)
                                        <span class="rounded-full bg-rt-red px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white dark:bg-rt-red dark:text-white">
                                            {{ $unread }}
                                        </span>
                                    @endif
                                </span>
                            </button>
                        @empty
                            <div class="flex h-full items-center justify-center px-6 text-center">
                                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_chats_yet') }}</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- ============================== RECHTE SPALTE: Unterhaltung ============================== --}}
                <div class="flex min-w-0 flex-1 flex-col">
                    @if (! $selectedChat)
                        {{-- Leerer Zustand --}}
                        <div class="flex flex-1 flex-col items-center justify-center gap-4 bg-rt-surface-muted/60 px-6 text-center dark:bg-rt-dark-canvas/40">
                            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-rt-accent-soft text-2xl text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="fad fa-comments" aria-hidden="true"></i>
                            </span>
                            <p class="max-w-xs text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_chat_selected') }}</p>
                        </div>
                    @else
                        {{-- Chat-Kopf --}}
                        <div class="flex items-center gap-3 border-b border-rt-border/60 px-4 py-3 dark:border-rt-dark-border/60">
                            @php
                                $headerAvatar = $selectedChat->avatarUrlFor($me);
                            @endphp
                            @if ($headerAvatar)
                                <img src="{{ $headerAvatar }}"
                                     alt="{{ $selectedChat->displayNameFor($me) }}"
                                     class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                            @else
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rt-accent-soft text-rt-accent ring-1 ring-rt-border/60 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-border/60">
                                    <i class="fad fa-users" aria-hidden="true"></i>
                                </span>
                            @endif
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                    {{ $selectedChat->displayNameFor($me) }}
                                </p>
                                @if ($selectedChat->isGroup())
                                    <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                        {{ trans_choice('app.members_count', $selectedChat->participants->count()) }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Nachrichtenbereich --}}
                        <div wire:poll.5s="pollTick"
                             wire:key="chat-pane-{{ $selectedChat->id }}"
                             x-data
                             x-init="$el.scrollTop = $el.scrollHeight"
                             x-on:chat:scroll-bottom.window="$nextTick(() => $el.scrollTo(0, $el.scrollHeight))"
                             class="flex-1 space-y-1 overflow-y-auto bg-rt-surface-muted/60 px-4 py-4 dark:bg-rt-dark-canvas/40">

                            @php
                                $items = $messages->values();
                            @endphp
                            @foreach ($items as $index => $message)
                                @php
                                    $prev   = $index > 0 ? $items[$index - 1] : null;
                                    $own    = (int) $message->user_id === (int) $me->id;
                                    $newDay = ! $prev || ! $prev->created_at->isSameDay($message->created_at);
                                    $chip   = $message->created_at->isToday()
                                        ? __('app.today')
                                        : ($message->created_at->isYesterday() ? __('app.yesterday') : $message->created_at->format('d.m.Y'));
                                    $showSender = $selectedChat->isGroup()
                                        && ! $own
                                        && ($newDay || ! $prev || (int) $prev->user_id !== (int) $message->user_id);
                                @endphp

                                <div wire:key="chat-msg-{{ $message->id }}" class="flex flex-col">
                                    {{-- Datums-Chip --}}
                                    @if ($newDay)
                                        <div class="flex justify-center py-2">
                                            <span class="rounded-full bg-rt-surface px-3 py-1 text-[11px] font-medium text-rt-muted shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                                                {{ $chip }}
                                            </span>
                                        </div>
                                    @endif

                                    {{-- Absendername (Gruppen, fremde Nachrichten) --}}
                                    @if ($showSender)
                                        <p class="mb-0.5 mr-auto max-w-[75%] px-1 text-[11px] font-semibold text-rt-accent dark:text-rt-dark-accent">
                                            {{ $message->sender?->name }}
                                        </p>
                                    @endif

                                    {{-- Sprechblase --}}
                                    <div class="{{ $own
                                            ? 'ml-auto rounded-2xl rounded-br-md bg-rt-red text-white dark:bg-rt-red dark:text-white'
                                            : 'mr-auto rounded-2xl rounded-bl-md bg-rt-surface text-rt-text ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60' }} max-w-[75%] px-3.5 py-2 text-sm shadow-rt-xs">
                                        <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                        <p class="mt-0.5 text-right text-[10px] leading-none opacity-70">
                                            {{ $message->created_at->format('H:i') }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Eingabezeile --}}
                        <div class="border-t border-rt-border/60 px-3 py-3 dark:border-rt-dark-border/60">
                            <form wire:submit.prevent="send" class="flex items-center gap-2">
                                <input type="text"
                                       wire:model="messageText"
                                       placeholder="{{ __('app.type_message') }}"
                                       autocomplete="off"
                                       class="h-10 min-w-0 flex-1 rounded-full border border-rt-border bg-rt-control px-4 text-sm text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring placeholder:text-rt-soft hover:border-rt-accent/40 focus:border-rt-accent focus:ring focus:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent">
                                <button type="submit"
                                        title="{{ __('app.type_message') }}"
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-sm transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark active:scale-95 dark:bg-rt-red dark:text-white">
                                    <i class="far fa-paper-plane" aria-hidden="true"></i>
                                    <span class="sr-only">{{ __('app.type_message') }}</span>
                                </button>
                            </form>
                            @error('messageText')
                                <p class="mt-1 px-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.page>

    {{-- ============================== MODAL: Neuer Chat / Neue Gruppe ============================== --}}
    <x-dialog-modal wire:model="showNewChat" maxWidth="md">
        <x-slot name="title">
            {{ $newChatTab === 'group' ? __('app.new_group') : __('app.new_chat') }}
        </x-slot>

        <x-slot name="content">
            {{-- Tab-Pills --}}
            <div class="mb-4 inline-flex rounded-lg bg-rt-surface-muted p-1 dark:bg-rt-dark-surface-muted">
                <button type="button"
                        wire:click="$set('newChatTab', 'direct')"
                        class="rounded-md px-3 py-1.5 text-sm font-semibold transition-all duration-300 ease-rt-spring {{ $newChatTab === 'direct'
                            ? 'bg-rt-surface text-rt-red shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-accent'
                            : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text' }}">
                    {{ __('app.new_chat') }}
                </button>
                <button type="button"
                        wire:click="$set('newChatTab', 'group')"
                        class="rounded-md px-3 py-1.5 text-sm font-semibold transition-all duration-300 ease-rt-spring {{ $newChatTab === 'group'
                            ? 'bg-rt-surface text-rt-red shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-accent'
                            : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text' }}">
                    {{ __('app.new_group') }}
                </button>
            </div>

            @if ($newChatTab === 'direct')
                {{-- Direktchat: Kontaktliste --}}
                <div class="max-h-72 space-y-1 overflow-y-auto pr-1">
                    @forelse ($contacts as $contact)
                        <button type="button"
                                wire:key="contact-{{ $contact->id }}"
                                wire:click="startDirect({{ $contact->id }})"
                                class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors duration-200 ease-rt-spring hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted">
                            <img src="{{ $contact->profile_photo_url }}"
                                 alt="{{ $contact->name }}"
                                 class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $contact->name }}</span>
                                <span class="block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $contact->email }}</span>
                            </span>
                        </button>
                    @empty
                        <p class="px-2 py-4 text-center text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_chats_yet') }}</p>
                    @endforelse
                </div>
            @else
                {{-- Gruppe: Name + Teilnehmer --}}
                <div class="space-y-4">
                    <div>
                        <x-ui.forms.label for="group-name" :value="__('app.group_name')" />
                        <x-ui.forms.input type="text"
                                          id="group-name"
                                          wire:model="groupName"
                                          autocomplete="off" />
                        @error('groupName')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <x-ui.forms.label :value="__('app.select_participants')" />
                        <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-rt-border/60 p-3 dark:border-rt-dark-border/60">
                            @foreach ($contacts as $contact)
                                <div wire:key="gp-row-{{ $contact->id }}">
                                    <x-ui.forms.checkbox :id="'gp-' . $contact->id"
                                                         :label="$contact->name"
                                                         value="{{ $contact->id }}"
                                                         wire:model="groupParticipants" />
                                </div>
                            @endforeach
                        </div>
                        @error('groupParticipants')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center gap-2">
                <x-ui.buttons.button-basic type="button" mode="basic" wire:click="$toggle('showNewChat')">
                    {{ __('app.cancel') }}
                </x-ui.buttons.button-basic>
                @if ($newChatTab === 'group')
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="createGroup">
                        {{ __('app.save') }}
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
