<div class="rt-chat-page relative h-full min-h-0 overflow-hidden p-2 sm:p-3"
     x-data="chatPaneNavigation(@js((bool) $selectedChat))"
     data-has-selected-chat="{{ $selectedChat ? 'true' : 'false' }}"
     data-mobile-pane="{{ $selectedChat ? 'chat' : 'list' }}"
     x-bind:data-mobile-pane="mobilePane"
     x-on:chat:pane-open.window="showChat()"
     x-on:touchstart.passive="touchStart($event)"
     x-on:touchend.passive="touchEnd($event)"
     x-on:touchcancel="cancelSwipe()"
     wire:loading.class="cursor-wait">
    @php
        $me = auth()->user();
    @endphp

    {{-- Messenger startet ohne zusaetzlichen Seitenkopf direkt unter der Topbar. --}}
    <div class="h-full min-h-0 overflow-hidden rounded-xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 sm:rounded-2xl dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <div class="rt-chat-panes relative flex h-full min-h-0 overflow-hidden">

                {{-- ============================== LINKE SPALTE: Chat-Liste ============================== --}}
                <div id="rt-chat-overview"
                     class="rt-chat-list-pane flex min-h-0 w-full shrink-0 flex-col border-rt-border/60 md:w-80 md:border-r dark:border-rt-dark-border/60"
                     x-bind:class="{ 'rt-chat-list-collapsed': listCollapsed }">

                    {{-- Kopfzeile --}}
                    <div class="flex shrink-0 items-center justify-between border-b border-rt-border/60 px-4 py-3 dark:border-rt-dark-border/60">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-rt-text dark:text-rt-dark-text">
                            {{ __('app.chats') }}
                        </h2>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                    x-on:click="toggleList()"
                                    class="hidden h-8 w-8 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text md:flex dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                                    aria-controls="rt-chat-overview"
                                    aria-label="{{ __('app.hide_chat_overview') }}"
                                    title="{{ __('app.hide_chat_overview') }}">
                                <i class="far fa-chevron-left" aria-hidden="true"></i>
                            </button>
                            <button type="button"
                                    wire:click="$set('showNewChat', true)"
                                    title="{{ __('app.new_chat') }}"
                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark active:scale-95 dark:bg-rt-red dark:text-white">
                                <i class="far fa-plus" aria-hidden="true"></i>
                                <span class="sr-only">{{ __('app.new_chat') }}</span>
                            </button>
                        </div>
                    </div>

                    {{-- Suche --}}
                    <div class="shrink-0 border-b border-rt-border/60 px-3 py-2.5 dark:border-rt-dark-border/60">
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
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain py-1">
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
                                    x-on:click="showChat()"
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
                                            {{ (int) $latest->user_id === (int) $me->id ? __('app.you') . ': ' : '' }}{{ $latest->isVoice() ? __('app.voice_message') : (filled($latest->body) ? $latest->body : __('app.chat_attachment')) }}
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
                <div class="rt-chat-conversation-pane flex min-h-0 min-w-0 flex-1 flex-col">
                    @if (! $selectedChat)
                        {{-- Leerer Zustand --}}
                        <div class="relative flex flex-1 flex-col items-center justify-center gap-4 bg-rt-surface-muted/60 px-6 text-center dark:bg-rt-dark-canvas/40">
                            <button type="button"
                                    x-on:click="toggleList()"
                                    class="absolute left-3 top-3 hidden h-9 w-9 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface hover:text-rt-text md:flex dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface dark:hover:text-white"
                                    aria-controls="rt-chat-overview"
                                    x-bind:aria-expanded="(! listCollapsed).toString()"
                                    x-bind:aria-label="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
                                    x-bind:title="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))">
                                <i class="far" x-bind:class="listCollapsed ? 'fa-chevron-right' : 'fa-chevron-left'" aria-hidden="true"></i>
                            </button>
                            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-rt-accent-soft text-2xl text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="fad fa-comments" aria-hidden="true"></i>
                            </span>
                            <p class="max-w-xs text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_chat_selected') }}</p>
                        </div>
                    @else
                        <div
                            class="contents"
                            x-data="chatRealtime({
                                chatId: {{ (int) $selectedChat->id }},
                                userId: {{ (int) $me->id }},
                                userName: @js($me->name),
                                typingText: @js(__('app.is_typing')),
                                recordingText: @js(__('app.recording')),
                                unsupportedText: @js(__('app.voice_recording_unsupported')),
                                microphoneErrorText: @js(__('app.voice_microphone_error')),
                                uploadErrorText: @js(__('app.voice_upload_failed'))
                            })"
                        >
                        {{-- Chat-Kopf --}}
                        <div class="flex shrink-0 items-center gap-2.5 border-b border-rt-border/60 px-3 py-2.5 sm:gap-3 sm:px-4 sm:py-3 dark:border-rt-dark-border/60">
                            @php
                                $headerAvatar = $selectedChat->avatarUrlFor($me);
                            @endphp
                            <button type="button"
                                    x-on:click="toggleList()"
                                    class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text md:flex dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                                    aria-controls="rt-chat-overview"
                                    x-bind:aria-expanded="(! listCollapsed).toString()"
                                    x-bind:aria-label="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
                                    x-bind:title="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))">
                                <i class="far" x-bind:class="listCollapsed ? 'fa-chevron-right' : 'fa-chevron-left'" aria-hidden="true"></i>
                            </button>
                            <button type="button"
                                    x-on:click="showList()"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text md:hidden dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                                    aria-label="{{ __('app.back') }}"
                                    title="{{ __('app.back') }}">
                                <i class="far fa-arrow-left" aria-hidden="true"></i>
                            </button>
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
                                <p x-cloak x-show="typingLabel" x-text="typingLabel" class="truncate text-xs font-medium text-rt-accent dark:text-rt-dark-accent"></p>
                            </div>
                        </div>

                        {{-- Nachrichtenbereich --}}
                        <div wire:poll.5s="pollTick"
                             wire:key="chat-pane-{{ $selectedChat->id }}"
                             x-data
                             x-init="$el.scrollTop = $el.scrollHeight"
                             x-on:chat:scroll-bottom.window="$nextTick(() => $el.scrollTo(0, $el.scrollHeight))"
                             class="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain bg-rt-surface-muted/60 px-2.5 py-3 sm:px-4 sm:py-4 dark:bg-rt-dark-canvas/40">

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
                                    $isRead = $own && $selectedChat->messageReadByAllRecipients($message, $me);
                                    $voiceFile = $message->voiceFile();
                                    $voiceConsumed = $message->view_once
                                        && ($own || ($message->hasBeenViewedBy($me) && ! $message->hasActiveVoicePlaybackFor($me)));
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
                                             : 'mr-auto rounded-2xl rounded-bl-md bg-rt-surface text-rt-text ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60' }} max-w-[88%] px-3 py-2 text-sm shadow-rt-xs sm:max-w-[75%] sm:px-3.5">
                                        @if (filled($message->body))
                                            <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                        @endif

                                        @if ($voiceFile)
                                            <x-chat.voice-message
                                                :message="$message"
                                                :file="$voiceFile"
                                                :own="$own"
                                                :consumed="$voiceConsumed"
                                            />
                                        @elseif ($message->files->isNotEmpty())
                                            <div class="space-y-2 {{ filled($message->body) ? 'mt-2' : '' }}">
                                                @foreach ($message->files as $file)
                                                    @php
                                                        $inlineUrl = route('chat.attachments', ['file' => $file]);
                                                        $downloadUrl = route('chat.attachments', ['file' => $file, 'download' => 1]);
                                                        $mime = strtolower((string) $file->mime_type);
                                                    @endphp

                                                    @if (str_starts_with($mime, 'image/'))
                                                        <button type="button"
                                                                @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                                                                class="group/image relative block max-w-[72vw] overflow-hidden rounded-xl bg-black/10 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 sm:max-w-full"
                                                                title="{{ __('app.preview') }}"
                                                                aria-label="{{ __('app.preview') }}: {{ $file->name }}">
                                                            <img src="{{ $inlineUrl }}"
                                                                 alt="{{ $file->name }}"
                                                                 loading="lazy"
                                                                 class="max-h-72 w-auto max-w-full object-contain transition duration-300 group-hover/image:scale-[1.02]">
                                                            <span class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 bg-gradient-to-t from-black/75 to-transparent px-3 pb-2 pt-8 text-[10px] text-white">
                                                                <span class="truncate">{{ $file->name }}</span>
                                                                <i class="far fa-expand shrink-0" aria-hidden="true"></i>
                                                            </span>
                                                        </button>
                                                    @elseif (str_starts_with($mime, 'audio/'))
                                                        <div x-data="chatAudioPlayer()" class="w-[min(17rem,72vw)] max-w-full py-0.5">
                                                            <audio x-ref="audio"
                                                                   preload="metadata"
                                                                   class="sr-only"
                                                                   src="{{ $inlineUrl }}"
                                                                   @loadedmetadata="metadataLoaded()"
                                                                   @timeupdate="timeUpdated()"
                                                                   @play="playing = true"
                                                                   @pause="playing = false"
                                                                   @ended="ended()">
                                                                <a href="{{ $downloadUrl }}">{{ $file->name }}</a>
                                                            </audio>

                                                            <div class="flex min-w-0 items-center gap-2.5">
                                                                <button type="button"
                                                                        @click="toggle()"
                                                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-black/10 text-sm transition hover:bg-black/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 dark:bg-white/10 dark:hover:bg-white/15"
                                                                        aria-label="{{ __('app.voice_message') }}">
                                                                    <i :class="playing ? 'fas fa-pause' : 'fas fa-play pl-0.5'" aria-hidden="true"></i>
                                                                </button>

                                                                <div class="min-w-0 flex-1">
                                                                    <div class="flex items-center gap-2 text-[10px] font-medium opacity-80">
                                                                        <span class="truncate">{{ __('app.voice_message') }}</span>
                                                                        <span class="ml-auto shrink-0 tabular-nums" x-text="formattedTime"></span>
                                                                    </div>
                                                                    <input type="range"
                                                                           min="0"
                                                                           step="0.1"
                                                                           :max="duration || 0"
                                                                           :value="currentTime"
                                                                           :style="`--rt-voice-progress: ${progress}%`"
                                                                           @input="seek($event.target.value)"
                                                                           class="rt-voice-progress mt-1 block w-full cursor-pointer"
                                                                           aria-label="{{ __('app.voice_message') }}">
                                                                </div>

                                                                <a href="{{ $downloadUrl }}"
                                                                   class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full opacity-70 transition hover:bg-black/10 hover:opacity-100 dark:hover:bg-white/10"
                                                                   title="{{ __('app.download') }}"
                                                                   aria-label="{{ __('app.download') }}">
                                                                    <i class="far fa-download" aria-hidden="true"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @elseif (str_starts_with($mime, 'video/'))
                                                        <video controls preload="metadata" class="max-h-72 w-full max-w-[72vw] rounded-xl bg-black sm:max-w-full" src="{{ $inlineUrl }}">
                                                            <a href="{{ $downloadUrl }}">{{ $file->name }}</a>
                                                        </video>
                                                    @else
                                                        <a href="{{ $downloadUrl }}"
                                                           class="flex min-w-0 max-w-[72vw] items-center gap-2 rounded-xl bg-black/10 px-3 py-2 text-left hover:bg-black/15 sm:max-w-full dark:bg-white/10 dark:hover:bg-white/15">
                                                            <i class="far fa-file-download shrink-0" aria-hidden="true"></i>
                                                            <span class="min-w-0">
                                                                <span class="block truncate text-xs font-semibold">{{ $file->name }}</span>
                                                                <span class="block text-[10px] opacity-70">{{ $file->getMimeTypeForHumans() }}</span>
                                                            </span>
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif

                                        <p class="mt-1 flex items-center justify-end gap-1 text-right text-[10px] leading-none opacity-80">
                                            @if ($own)
                                                <button
                                                    type="button"
                                                    wire:click="deleteMessage({{ $message->id }})"
                                                    wire:confirm="{{ __('app.delete_chat_message_confirm') }}"
                                                    class="mr-auto inline-flex h-6 w-6 items-center justify-center rounded-full opacity-65 transition hover:bg-black/10 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 dark:hover:bg-white/10"
                                                    title="{{ __('app.delete_chat_message') }}"
                                                    aria-label="{{ __('app.delete_chat_message') }}"
                                                >
                                                    <i class="far fa-trash-alt" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                            <span>{{ $message->created_at->format('H:i') }}</span>
                                            @if ($own)
                                                <i class="far fa-check-double {{ $isRead ? 'text-sky-300' : 'text-white/60' }}"
                                                   title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"
                                                   aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"></i>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Eingabezeile --}}
                        <div class="shrink-0 border-t border-rt-border/60 bg-rt-surface px-2 py-2.5 sm:px-3 sm:py-3 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface">
                            @if ($uploads !== [])
                                <div class="mb-2 flex flex-wrap gap-2 px-1">
                                    @foreach ($uploads as $index => $upload)
                                        <span wire:key="chat-upload-{{ $index }}" class="inline-flex max-w-full items-center gap-2 rounded-full bg-rt-surface-muted px-3 py-1.5 text-xs text-rt-text ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-white dark:ring-rt-dark-border/60">
                                            <i class="far fa-paperclip" aria-hidden="true"></i>
                                            <span class="max-w-48 truncate">{{ $upload->getClientOriginalName() }}</span>
                                            <button type="button" wire:click="removeUpload({{ $index }})" class="text-rt-muted hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent" aria-label="{{ __('app.remove') }}">
                                                <i class="far fa-times" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <form wire:submit.prevent="send" class="flex items-center gap-1.5 sm:gap-2">
                                <input id="chat-attachments-{{ $selectedChat->id }}"
                                       type="file"
                                       wire:model="uploads"
                                       multiple
                                       accept="audio/*,video/*,image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                                       class="sr-only">

                                <div x-show.important="!recording && !sendingVoice" data-chat-composer-mode="text" class="flex min-w-0 flex-1 items-center gap-1.5 sm:gap-2">
                                    <label for="chat-attachments-{{ $selectedChat->id }}"
                                           title="{{ __('app.add_attachment') }}"
                                           class="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-rt-border bg-rt-surface text-rt-text shadow-rt-xs transition hover:bg-rt-surface-muted hover:text-rt-red sm:h-10 sm:w-10 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                                        <i class="far fa-paperclip" aria-hidden="true"></i>
                                        <span class="sr-only">{{ __('app.add_attachment') }}</span>
                                    </label>

                                    <button type="button"
                                            @click="startRecording()"
                                            title="{{ __('app.voice_message') }}"
                                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-rt-border bg-rt-surface text-rt-text shadow-rt-xs transition hover:border-rt-red/50 hover:bg-rt-surface-muted hover:text-rt-red active:scale-95 sm:h-10 sm:w-10 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                                        <i class="far fa-microphone" aria-hidden="true"></i>
                                        <span class="sr-only">{{ __('app.voice_message') }}</span>
                                    </button>

                                    <input type="text"
                                           wire:model="messageText"
                                           @input.debounce.250ms="sendTyping()"
                                           placeholder="{{ __('app.type_message') }}"
                                           autocomplete="off"
                                           class="h-11 min-w-0 flex-1 rounded-full border border-rt-border bg-rt-control px-4 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring placeholder:text-rt-soft hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 sm:h-10 sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20">
                                    <button type="submit"
                                            title="{{ __('app.type_message') }}"
                                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-sm transition-all duration-200 ease-rt-spring hover:bg-rt-red-dark active:scale-95 sm:h-10 sm:w-10 dark:bg-rt-red dark:text-white">
                                        <i class="far fa-paper-plane" aria-hidden="true"></i>
                                        <span class="sr-only">{{ __('app.type_message') }}</span>
                                    </button>
                                </div>

                                <div
                                    x-cloak
                                    x-show.important="recording || sendingVoice"
                                    data-chat-composer-mode="voice"
                                    x-transition:enter="transition duration-200 ease-out"
                                    x-transition:enter-start="translate-y-1 opacity-0"
                                    x-transition:enter-end="translate-y-0 opacity-100"
                                    data-no-chat-swipe
                                    class="rt-voice-recorder flex min-w-0 flex-1 items-center gap-2 rounded-2xl border border-rt-border bg-rt-control px-1.5 py-1 shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-control"
                                >
                                    <button
                                        type="button"
                                        @click="cancelRecording()"
                                        :disabled="sendingVoice"
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface hover:text-rt-red disabled:cursor-not-allowed disabled:opacity-40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface"
                                        title="{{ __('app.cancel_recording') }}"
                                        aria-label="{{ __('app.cancel_recording') }}"
                                    >
                                        <i class="far fa-trash-alt" aria-hidden="true"></i>
                                    </button>

                                    <div class="flex min-w-0 flex-1 items-center gap-2">
                                        <span class="rt-recording-dot h-2.5 w-2.5 shrink-0 rounded-full bg-rt-red" aria-hidden="true"></span>
                                        <span class="sr-only">{{ __('app.recording') }}</span>
                                        <span class="w-[4.75rem] shrink-0 truncate text-xs font-semibold tabular-nums text-rt-text dark:text-white" x-text="sendingVoice ? @js(__('app.voice_sending')) : recordingLabel"></span>
                                        <div class="rt-recording-wave flex h-7 min-w-0 flex-1 items-center justify-center gap-[3px] overflow-hidden" aria-hidden="true">
                                            <template x-for="index in 18" :key="index">
                                                <span :style="`--rt-wave-index: ${index}`"></span>
                                            </template>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        @click="toggleViewOnce()"
                                        :disabled="sendingVoice"
                                        :aria-pressed="viewOnce.toString()"
                                        :class="viewOnce ? 'border-rt-red bg-rt-red text-white' : 'border-rt-border text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted'"
                                        class="rt-voice-once-button flex h-9 w-9 shrink-0 items-center justify-center rounded-full border text-xs font-bold transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="{{ __('app.voice_once_hint') }}"
                                        aria-label="{{ __('app.voice_once') }}"
                                    >1</button>

                                    <button
                                        type="button"
                                        @click="sendRecording()"
                                        :disabled="sendingVoice"
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-sm transition hover:bg-rt-red-dark active:scale-95 disabled:cursor-wait disabled:opacity-70"
                                        title="{{ __('app.send_voice_message') }}"
                                        aria-label="{{ __('app.send_voice_message') }}"
                                    >
                                        <i :class="sendingVoice ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </form>
                            @error('messageText')
                                <p class="mt-1 px-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('uploads')
                                <p class="mt-1 px-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('uploads.*')
                                <p class="mt-1 px-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('voiceUpload')
                                <p class="mt-1 px-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p wire:loading wire:target="uploads" class="mt-1 px-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.uploading') }}</p>
                        </div>
                        </div>
                    @endif
                </div>
        </div>
    </div>

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
