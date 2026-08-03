<div
    class="relative flex h-full min-h-[28rem] flex-col overflow-hidden"
    @unless ($readOnly)
        wire:poll.5s="refreshMessages"
    @endunless
    x-data="{
        previousScrollHeight: 0,
        scrollToMessage(id) {
            const node = this.$root.querySelector(`[data-call-chat-message='${id}']`);
            if (!node) return;
            node.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
            node.classList.add('ring-2', 'ring-rt-accent');
            window.setTimeout(() => node.classList.remove('ring-2', 'ring-rt-accent'), 1400);
        },
        rememberScroll() {
            this.previousScrollHeight = this.$refs.transcript?.scrollHeight || 0;
        },
        restoreOlderScroll() {
            this.$nextTick(() => {
                if (this.$refs.transcript) this.$refs.transcript.scrollTop += this.$refs.transcript.scrollHeight - this.previousScrollHeight;
            });
        },
        scrollBottom() {
            this.$nextTick(() => {
                if (this.$refs.transcript) this.$refs.transcript.scrollTop = this.$refs.transcript.scrollHeight;
            });
        },
    }"
    x-on:call-chat:scroll-bottom.window="scrollBottom()"
    x-on:call-chat:older-loaded.window="restoreOlderScroll()"
    x-on:call-chat:focus-composer.window="$nextTick(() => $refs.composer?.focus())"
>
    <div x-ref="transcript" class="scroll-container min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-4 sm:px-4">
        @if ($hasOlder)
            <div class="mb-4 text-center">
                <button
                    type="button"
                    wire:click="loadOlder"
                    x-on:click="rememberScroll()"
                    class="inline-flex min-h-11 items-center gap-2 rounded-full bg-rt-surface-muted px-4 text-xs font-bold text-rt-muted transition hover:text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:hover:text-rt-dark-text"
                >
                    <i class="far fa-clock-rotate-left" aria-hidden="true"></i>
                    {{ __('app.chat_load_older_messages') }}
                </button>
            </div>
        @endif

        @forelse ($messages as $message)
            @php
                $mine = (int) $message->user_id === (int) auth()->id();
                $reactionGroups = $message->reactions->groupBy('emoji');
                $myReaction = $message->reactions->firstWhere('user_id', auth()->id())?->emoji;
                $reply = $message->replyTo;
                $isLiveLocation = $message->isLiveLocation();
            @endphp
            <article
                wire:key="call-chat-message-{{ $message->id }}"
                data-call-chat-message="{{ $message->id }}"
                data-no-chat-swipe
                tabindex="0"
                class="group mb-3 flex scroll-mt-6 items-end gap-2 rounded-xl outline-none transition-shadow focus-visible:ring-2 focus-visible:ring-rt-accent/60 {{ $mine ? 'justify-end' : 'justify-start' }}"
                x-data="chatMessageActions({ messageId: {{ $message->id }}, controllerId: 'call-chat-{{ $message->id }}', disabled: @js($readOnly), canReact: @js(! $readOnly && ! $mine) })"
                data-chat-message-controller="call-chat-{{ $message->id }}"
                x-ref="messageBubble"
                @unless ($readOnly)
                    x-on:click="handleClick($event)"
                    x-on:contextmenu.stop="openActionsAtPointer($event)"
                    x-on:keydown="handleKeyboard($event)"
                    x-on:pointerdown="startLongPress($event)"
                    x-on:pointermove="moveLongPress($event)"
                    x-on:pointerup="finishLongPress($event)"
                    x-on:pointercancel="cancelLongPress()"
                    x-on:pointerleave="cancelLongPress()"
                @endunless
            >
                @unless ($mine)
                    <x-chat.avatar :src="$message->sender?->profile_photo_url" :name="$message->sender?->name ?? '?'" size="xs" />
                @endunless

                <div class="{{ $reactionGroups->isNotEmpty() ? 'rt-chat-message-stack--reacted' : '' }} relative max-w-[86%] sm:max-w-[78%]">
                    <div class="flex items-start gap-1 {{ $mine ? 'flex-row-reverse' : '' }}">
                        <div @class([
                            'min-w-0 rounded-2xl px-3.5 py-2.5 shadow-sm',
                            'rt-chat-message--live-location' => $isLiveLocation,
                            'rounded-br-md bg-rt-accent text-white' => $mine,
                            'rounded-bl-md bg-rt-surface-muted text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-text' => ! $mine,
                        ])>
                            @if ($reply)
                                <button
                                    type="button"
                                    x-on:click.stop="scrollToMessage({{ $reply->id }})"
                                    class="mb-2 block w-full rounded-lg border-l-2 border-current/50 bg-black/10 px-2.5 py-2 text-left text-[11px] leading-4"
                                >
                                    <span class="block truncate font-bold opacity-90">{{ $reply->sender?->name ?? __('app.calls_unknown_participant') }}</span>
                                    <span class="block truncate opacity-75">
                                        @if ($reply->trashed())
                                            {{ __('app.chat_original_message_unavailable') }}
                                        @elseif ($reply->view_once)
                                            {{ __('app.chat_view_once_voice_message') }}
                                        @else
                                            {{ $reply->replyPreviewText() }}
                                        @endif
                                    </span>
                                </button>
                            @endif

                            @if ($isLiveLocation)
                                <x-chat.live-location-card :message="$message" :own="$mine" :can-stop="! $readOnly && $mine" />
                            @else
                                @if (filled($message->body))
                                    <p class="whitespace-pre-wrap break-words text-sm leading-5">{{ $message->body }}</p>
                                @endif

                                @if ($message->files->isNotEmpty())
                                <div class="mt-2 space-y-1.5">
                                    @foreach ($message->files as $file)
                                        <a
                                            href="{{ route('chat.attachments', $file) }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="flex min-h-11 items-center gap-2 rounded-xl bg-black/10 px-3 py-2 text-xs font-semibold transition hover:bg-black/15"
                                        >
                                            <i class="far fa-paperclip shrink-0" aria-hidden="true"></i>
                                            <span class="min-w-0 flex-1 truncate">{{ $file->name }}</span>
                                            <span class="shrink-0 opacity-70">{{ $file->size_formatted }}</span>
                                        </a>
                                    @endforeach
                                </div>
                                @endif
                            @endif

                            <p class="mt-1 text-right text-[9px] font-semibold opacity-60">{{ $message->created_at?->format('H:i') }}</p>
                        </div>

                        @unless ($readOnly)
                            <x-chat.message-dropdown
                                :message-id="$message->id"
                                controller-id="call-chat-{{ $message->id }}"
                                :own="$mine"
                                :quick-reactions="$quickReactions"
                                :allowed-reactions="$allowedReactions"
                                :my-reaction="$myReaction"
                                react-method="setReaction"
                                reply-method="startReply"
                                delete-method="deleteMessage"
                                :delete-label="__('app.delete')"
                                trigger-variant="ellipsis"
                                dropdown-id="call-chat-message-{{ $message->id }}-actions"
                            />
                        @endunless
                    </div>

                    @if ($reactionGroups->isNotEmpty())
                        <div class="rt-chat-reactions rt-chat-reactions--overlay {{ $mine ? 'rt-chat-reactions--own' : 'rt-chat-reactions--other' }} flex max-w-full flex-nowrap items-center gap-1 px-1" data-chat-action-ignore data-no-chat-swipe aria-label="{{ __('app.chat_message_reactions') }}">
                            @foreach ($allowedReactions as $emoji)
                                @if ($reactionGroups->has($emoji))
                                    @php $reactions = $reactionGroups->get($emoji); @endphp
                                    @if ($readOnly || $mine)
                                        <span
                                            class="rt-chat-reaction-chip inline-flex items-center gap-1 px-0.5 text-sm"
                                            title="{{ $reactions->pluck('user.name')->filter()->join(', ') }}"
                                            aria-label="{{ trans_choice('app.chat_reaction_count', $reactions->count(), ['emoji' => $emoji, 'count' => $reactions->count()]) }}"
                                        >
                                            <span aria-hidden="true">{{ $emoji }}</span>
                                            @if ($reactions->count() > 1)<span class="text-[10px] font-extrabold tabular-nums">{{ $reactions->count() }}</span>@endif
                                        </span>
                                    @else
                                        <x-chat.reaction-dropdown
                                            :message-id="$message->id"
                                            :emoji="$emoji"
                                            :count="$reactions->count()"
                                            :names="$reactions->pluck('user.name')->filter()->join(', ')"
                                            :my-reaction="$myReaction"
                                            react-method="setReaction"
                                            remove-method="removeReaction"
                                            dropdown-id="call-chat-reaction-{{ $message->id }}-{{ md5($emoji) }}"
                                        />
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="flex min-h-64 flex-col items-center justify-center px-6 text-center text-rt-muted dark:text-rt-dark-muted">
                <i class="far fa-message-dots text-2xl" aria-hidden="true"></i>
                <p class="mt-3 text-sm font-semibold">{{ __('app.calls_chat_empty') }}</p>
            </div>
        @endforelse
    </div>

    @if ($readOnly)
        <div class="border-t border-rt-border/60 bg-rt-surface-muted px-4 py-3 text-center text-xs font-semibold text-rt-muted dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
            <i class="far fa-lock mr-1" aria-hidden="true"></i>
            {{ __('app.calls_chat_read_only') }}
        </div>
    @else
        <form wire:submit="send" class="border-t border-rt-border/60 bg-rt-surface px-3 py-3 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface">
            @if ($replyingTo)
                <div class="mb-2 flex items-center gap-2 rounded-xl bg-rt-surface-muted px-3 py-2 dark:bg-rt-dark-surface-muted">
                    <i class="far fa-reply text-rt-accent" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[11px] font-bold text-rt-text dark:text-rt-dark-text">{{ $replyingTo->sender?->name }}</span>
                        <span class="block truncate text-[10px] text-rt-muted dark:text-rt-dark-muted">
                            {{ $replyingTo->replyPreviewText() }}
                        </span>
                    </span>
                    <button type="button" wire:click="cancelReply" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-rt-muted" aria-label="{{ __('app.cancel') }}">
                        <i class="far fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
            @endif

            @if ($uploads !== [])
                <div class="mb-2 flex flex-wrap gap-2">
                    @foreach ($uploads as $index => $upload)
                        <span class="inline-flex min-h-10 max-w-full items-center gap-2 rounded-xl bg-rt-surface-muted px-2.5 text-xs text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-text" wire:key="call-upload-{{ $index }}">
                            <i class="far fa-paperclip" aria-hidden="true"></i>
                            <span class="max-w-40 truncate">{{ $upload->getClientOriginalName() }}</span>
                            <button type="button" wire:click="removeUpload({{ $index }})" class="inline-flex h-9 w-9 items-center justify-center" aria-label="{{ __('app.remove') }}">
                                <i class="far fa-xmark" aria-hidden="true"></i>
                            </button>
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="flex items-end gap-2">
                <label class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted" aria-label="{{ __('app.add_attachment') }}">
                    <input
                        type="file"
                        wire:model="uploads"
                        multiple
                        accept="audio/*,video/*,image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                        class="sr-only"
                    >
                    <i class="far fa-paperclip" aria-hidden="true"></i>
                </label>
                <x-chat.live-location-share
                    :chat-id="$room->call_chat_id"
                    :start-url="route('chat.live-locations.store', ['chat' => $room->call_chat_id])"
                    :reply-to-message-id="$replyingTo?->id"
                    context="call"
                    class="[&_.rt-chat-composer-action]:h-11 [&_.rt-chat-composer-action]:w-11 [&_.rt-chat-composer-action]:text-rt-muted"
                />
                <textarea
                    x-ref="composer"
                    wire:model="messageText"
                    rows="1"
                    maxlength="5000"
                    class="min-h-11 max-h-32 min-w-0 flex-1 resize-none rounded-xl border-0 bg-rt-surface-muted px-3 py-2.5 text-sm text-rt-text outline-none ring-1 ring-rt-border/60 focus:ring-2 focus:ring-rt-accent/40 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60"
                    placeholder="{{ __('app.calls_chat_message_placeholder') }}"
                    x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send(); }"
                ></textarea>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="send,uploads"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent text-white transition hover:opacity-90 disabled:opacity-50"
                    aria-label="{{ __('app.send') }}"
                >
                    <i class="far fa-paper-plane" aria-hidden="true"></i>
                </button>
            </div>
            <x-input-error for="messageText" class="mt-1" />
            <x-input-error for="uploads" class="mt-1" />
            <x-input-error for="uploads.*" class="mt-1" />
        </form>
    @endif

</div>
