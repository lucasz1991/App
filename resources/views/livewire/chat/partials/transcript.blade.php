<div
    wire:poll.visible.2s="pollTick"
    wire:key="chat-pane-{{ $selectedChat->id }}"
    x-data="chatTranscriptScroll()"
    x-on:chat:scroll-bottom.window="$nextTick(() => scrollToLatest(true))"
    x-on:chat:older-loaded.window="$nextTick(() => restorePrependPosition())"
    x-on:chat:scroll-to-message.window="scrollToMessage($event.detail?.messageId)"
    class="rt-chat-transcript min-h-0 flex-1 space-y-1.5 overflow-y-auto overscroll-contain px-2.5 py-4 sm:px-5 sm:py-6 lg:px-8"
>
    @if ($hasOlderMessages)
        <div class="flex justify-center pb-3" data-chat-history-anchor>
            <button
                type="button"
                wire:click="loadOlderMessages"
                wire:loading.attr="disabled"
                wire:target="loadOlderMessages"
                x-on:click="rememberPrependPosition()"
                class="rt-chat-load-older inline-flex min-h-11 items-center gap-2 rounded-full px-4 py-2 text-xs font-bold disabled:cursor-wait disabled:opacity-60"
            >
                <i class="far fa-arrow-up" aria-hidden="true"></i>
                <span>{{ __('app.chat_load_older_messages') }}</span>
            </button>
        </div>
    @endif

    @php
        $items = $messages->values();
    @endphp

    @foreach ($items as $index => $message)
        @php
            $prev = $index > 0 ? $items[$index - 1] : null;
            $own = (int) $message->user_id === (int) $me->id;
            $deleted = $message->trashed();
            $newDay = ! $prev || ! $prev->created_at->isSameDay($message->created_at);
            $chip = $message->created_at->isToday()
                ? __('app.today')
                : ($message->created_at->isYesterday() ? __('app.yesterday') : $message->created_at->format('d.m.Y'));
            $showSender = $selectedChat->isGroup() && ! $own;
            $isRead = $own && ! $deleted && $selectedChat->messageReadByAllRecipients($message, $me);
            $isLiveLocation = ! $deleted && $message->isLiveLocation();
            $voiceFile = $deleted ? null : $message->voiceFile();
            $voiceConsumed = $voiceFile && $message->view_once
                && ($own || ($message->hasBeenViewedBy($me) && ! $message->hasActiveVoicePlaybackFor($me)));
            $messageSurface = $deleted
                ? 'rt-chat-message--deleted'
                : ($isLiveLocation
                    ? 'rt-chat-message--live-location'
                    : ($voiceFile
                    ? 'rt-chat-message--voice'
                    : ($message->files->isNotEmpty() ? 'rt-chat-message--attachment' : 'rt-chat-message--text')));
            $reactionGroups = $message->reactions->groupBy('emoji');
            $myReaction = $message->reactions->firstWhere('user_id', $me->id)?->emoji;
        @endphp

        <div
            wire:key="chat-msg-{{ $message->id }}"
            data-chat-message-row
            data-chat-message-id="{{ $message->id }}"
            class="rt-chat-message-row flex w-full flex-col"
        >
            @if ($newDay)
                <div class="flex justify-center py-3">
                    <span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">
                        {{ $chip }}
                    </span>
                </div>
            @endif

            <div class="rt-chat-message-line {{ $own ? 'rt-chat-message-line--own ml-auto flex-row-reverse' : 'mr-auto' }} {{ $selectedChat->isGroup() ? 'rt-chat-message-line--group' : 'rt-chat-message-line--direct' }} flex items-end gap-2">
                @if ($selectedChat->isGroup())
                    <x-chat.avatar
                        :src="$message->sender?->profile_photo_url"
                        :name="$message->sender?->name ?? __('app.unknown')"
                        size="xs"
                        class="rt-chat-message-avatar mb-0.5"
                    />
                @endif

                <div
                    x-data="chatMessageActions({ messageId: {{ $message->id }}, controllerId: 'chat-{{ $message->id }}', disabled: @js($deleted), canReact: @js(! $own && ! $deleted) })"
                    data-chat-message-controller="chat-{{ $message->id }}"
                    class="rt-chat-message-stack {{ $reactionGroups->isNotEmpty() ? 'rt-chat-message-stack--reacted' : '' }} {{ $own ? 'items-end' : 'items-start' }} relative flex min-w-0 max-w-full flex-col"
                >
                    <div class="rt-chat-message-bubble-wrap relative max-w-full">
                        <div
                            data-rt-chat-message="{{ $own ? 'own' : 'other' }}"
                            tabindex="{{ $deleted ? '-1' : '0' }}"
                            x-ref="messageBubble"
                            x-on:contextmenu.stop="openActionsAtPointer($event)"
                            x-on:pointerdown="startLongPress($event)"
                            x-on:pointermove="moveLongPress($event)"
                            x-on:pointerup="finishLongPress($event)"
                            x-on:pointercancel="cancelLongPress()"
                            x-on:keydown="handleKeyboard($event)"
                            x-on:click="handleClick($event)"
                            class="rt-chat-message {{ $own
                                ? 'rt-chat-message--own rt-chat-message--actionable rounded-br-md'
                                : 'rt-chat-message--other rt-chat-message--actionable rounded-bl-md' }} {{ $messageSurface }} relative rounded-[1.15rem] px-3.5 py-2.5 text-[13px] leading-5 sm:px-4"
                        >
                        @unless ($deleted)
                            <x-chat.message-dropdown
                                :message-id="$message->id"
                                controller-id="chat-{{ $message->id }}"
                                :own="$own"
                                :quick-reactions="$quickReactions"
                                :allowed-reactions="$allowedReactions"
                                :my-reaction="$myReaction"
                                delete-method="requestDeleteMessage"
                                trigger-variant="bubble"
                                dropdown-id="chat-message-{{ $message->id }}-actions"
                            />
                        @endunless

                        @if ($showSender)
                            <p class="rt-chat-message-sender mb-1 text-[10px] font-extrabold tracking-[0.01em]">
                                {{ $message->sender?->name }}
                            </p>
                        @endif

                        @if ($message->reply_to_message_id)
                            @php
                                $reply = $message->replyTo;
                            @endphp
                            @if ($reply)
                                <button
                                    type="button"
                                    x-on:click.stop="$dispatch('chat:scroll-to-message', { messageId: {{ $reply->id }} })"
                                    data-no-chat-swipe
                                    class="rt-chat-reply-quote mb-2 block w-full rounded-xl px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                >
                                    <span class="block truncate text-[10px] font-extrabold">{{ $reply->sender?->name ?? __('app.unknown') }}</span>
                                    <span class="block truncate text-[11px] opacity-80">{{ $reply->replyPreviewText() }}</span>
                                </button>
                            @else
                                <div class="rt-chat-reply-quote mb-2 rounded-xl px-3 py-2 text-[11px] italic opacity-80">
                                    {{ __('app.chat_original_message_unavailable') }}
                                </div>
                            @endif
                        @endif

                        @if ($deleted)
                            <p class="rt-chat-deleted-copy flex items-center gap-2 italic">
                                <i class="far fa-ban" aria-hidden="true"></i>
                                <span>{{ __('app.chat_message_deleted') }}</span>
                            </p>
                        @else
                            @if ($isLiveLocation)
                                <x-chat.live-location-card :message="$message" :own="$own" />
                            @else
                                @if (filled($message->body))
                                    <p class="rt-chat-message-copy whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                @endif

                                @if ($voiceFile)
                                    <x-chat.voice-message :message="$message" :file="$voiceFile" :own="$own" :consumed="$voiceConsumed" />
                                @elseif ($message->files->isNotEmpty())
                                <div class="space-y-2 {{ filled($message->body) ? 'mt-2' : '' }}">
                                    @foreach ($message->files as $file)
                                        @php
                                            $inlineUrl = route('chat.attachments', ['file' => $file]);
                                            $mime = strtolower((string) $file->mime_type);
                                            $isImage = str_starts_with($mime, 'image/');
                                            $isVideo = str_starts_with($mime, 'video/');
                                        @endphp

                                        <button
                                            type="button"
                                            x-on:click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                                            data-no-chat-swipe
                                            class="{{ $isImage || $isVideo ? 'rt-chat-image-preview group/image relative block overflow-hidden rounded-2xl bg-black/10' : 'rt-chat-file-card flex items-center gap-3 rounded-xl px-2.5 py-2' }} min-w-0 max-w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                            title="{{ __('app.preview') }}"
                                            aria-label="{{ __('app.preview') }}: {{ $file->name }}"
                                        >
                                            @if ($isImage)
                                                <img src="{{ $inlineUrl }}" alt="{{ $file->name }}" loading="lazy" class="max-h-80 w-auto max-w-full object-contain transition duration-500 ease-rt-spring group-hover/image:scale-[1.015]">
                                            @elseif ($isVideo)
                                                <span class="relative flex min-h-36 w-full max-w-[21rem] items-center justify-center bg-black/85 text-white">
                                                    <video src="{{ $inlineUrl }}" preload="metadata" muted playsinline aria-hidden="true" class="absolute inset-0 h-full w-full object-cover opacity-75"></video>
                                                    <i class="fas fa-play relative z-10 text-xl drop-shadow-lg" aria-hidden="true"></i>
                                                </span>
                                            @else
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-black/10 dark:bg-white/10">
                                                    <i class="far {{ str_starts_with($mime, 'audio/') ? 'fa-file-audio' : 'fa-file' }}" aria-hidden="true"></i>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-semibold">{{ $file->name }}</span>
                                                    <span class="block text-[10px] opacity-70">{{ $file->getMimeTypeForHumans() }}</span>
                                                </span>
                                            @endif

                                            @if ($isImage || $isVideo)
                                                <span class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 bg-gradient-to-t from-black/85 via-black/35 to-transparent px-3 pb-2.5 pt-10 text-[10px] font-semibold text-white">
                                                    <span class="truncate">{{ $file->name }}</span>
                                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/15 backdrop-blur-md"><i class="far fa-expand" aria-hidden="true"></i></span>
                                                </span>
                                            @else
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-70"><i class="far fa-eye" aria-hidden="true"></i></span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                                @endif
                            @endif
                        @endif

                        </div>

                        @if (! $deleted && $reactionGroups->isNotEmpty())
                            <div class="rt-chat-reactions rt-chat-reactions--overlay {{ $own ? 'rt-chat-reactions--own' : 'rt-chat-reactions--other' }} flex max-w-full flex-nowrap items-center gap-1 px-1" data-chat-action-ignore data-no-chat-swipe aria-label="{{ __('app.chat_message_reactions') }}">
                                @foreach ($allowedReactions as $emoji)
                                    @if ($reactionGroups->has($emoji))
                                        @php
                                            $count = $reactionGroups->get($emoji)->count();
                                        @endphp
                                        @if ($own)
                                            <span
                                                class="rt-chat-reaction-chip inline-flex items-center justify-center gap-1 px-0.5 text-sm"
                                                aria-label="{{ trans_choice('app.chat_reaction_count', $count, ['emoji' => $emoji, 'count' => $count]) }}"
                                            >
                                                <span aria-hidden="true">{{ $emoji }}</span>
                                                @if ($count > 1)<span class="text-[10px] font-extrabold tabular-nums">{{ $count }}</span>@endif
                                            </span>
                                        @else
                                            <x-chat.reaction-dropdown
                                                :message-id="$message->id"
                                                :emoji="$emoji"
                                                :count="$count"
                                                :names="$reactionGroups->get($emoji)->pluck('user.name')->filter()->join(', ')"
                                                :my-reaction="$myReaction"
                                                dropdown-id="chat-reaction-{{ $message->id }}-{{ md5($emoji) }}"
                                            />
                                        @endif
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="rt-chat-message-meta rt-chat-message-meta--{{ $own ? 'own' : 'other' }} flex items-center gap-1 text-[9px] font-semibold leading-none">
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('H:i') }}</time>
                        @if ($own && ! $deleted)
                            <i class="rt-chat-read-indicator far fa-check-double {{ $isRead ? 'is-read' : 'is-delivered' }}" title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}" aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"></i>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div
        x-cloak
        x-show.important="typingLabel"
        x-effect="if (typingLabel && stickToBottom) $nextTick(() => { if (stickToBottom) scrollToLatest(true) })"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="translate-y-1 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition duration-100 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="rt-chat-typing-row mr-auto flex items-end"
        data-chat-typing-indicator
        aria-hidden="true"
    >
        <span class="rt-chat-typing-bubble inline-flex items-center gap-1.5">
            <span></span>
            <span></span>
            <span></span>
        </span>
    </div>
</div>
