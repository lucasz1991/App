@php
    $quickReactions = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    $allReactions = ['😀', '😃', '😄', '😁', '😊', '😍', '🥰', '😘', '😎', '🤔', '🙄', '😬', '😢', '😭', '😡', '🤯', '😴', '👏', '🙌', '👍', '👎', '❤️', '💔', '🔥', '🎉', '✅', '👀', '🙏', '💯'];
@endphp

<div
    class="relative flex h-full min-h-[28rem] flex-col overflow-hidden"
    wire:poll.5s="refreshMessages"
    x-data="{
        actionOpen: false,
        actionMessageId: null,
        actionMine: false,
        actionX: 0,
        actionY: 0,
        expandedReactions: false,
        pressTimer: null,
        pressX: 0,
        pressY: 0,
        longPressed: false,
        previousScrollHeight: 0,
        openActions(event, id, mine, pointer = false) {
            const source = event.currentTarget || event.target;
            const rect = source?.getBoundingClientRect?.();
            const requestedX = pointer && event.clientX ? event.clientX : (rect ? rect.right : window.innerWidth / 2);
            const requestedY = pointer && event.clientY ? event.clientY : (rect ? rect.bottom + 6 : window.innerHeight / 2);
            this.actionX = Math.max(8, Math.min(requestedX, window.innerWidth - 304));
            this.actionY = Math.max(8, Math.min(requestedY, window.innerHeight - 250));
            this.actionMessageId = id;
            this.actionMine = mine;
            this.expandedReactions = false;
            this.actionOpen = true;
            this.$nextTick(() => this.$refs.actionMenu?.focus());
        },
        startPress(event, id, mine) {
            if (event.pointerType === 'mouse' || event.target.closest('button, a, input, textarea, audio, video')) return;
            this.cancelPress();
            this.pressX = event.clientX;
            this.pressY = event.clientY;
            this.longPressed = false;
            this.pressTimer = window.setTimeout(() => {
                this.longPressed = true;
                this.openActions(event, id, mine, true);
            }, 500);
        },
        movePress(event) {
            if (!this.pressTimer) return;
            if (Math.hypot(event.clientX - this.pressX, event.clientY - this.pressY) > 10) this.cancelPress();
        },
        cancelPress() {
            if (this.pressTimer) window.clearTimeout(this.pressTimer);
            this.pressTimer = null;
        },
        finishPress(event) {
            this.cancelPress();
            if (this.longPressed) {
                event.preventDefault();
                event.stopPropagation();
                this.longPressed = false;
            }
        },
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
    x-on:keydown.escape.window="actionOpen = false; cancelPress()"
    x-on:pointercancel.window="cancelPress()"
    x-on:scroll.window="cancelPress()"
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
                $reply = $message->replyTo;
            @endphp
            <article
                wire:key="call-chat-message-{{ $message->id }}"
                data-call-chat-message="{{ $message->id }}"
                data-no-chat-swipe
                tabindex="0"
                class="group mb-3 flex scroll-mt-6 items-end gap-2 rounded-xl outline-none transition-shadow focus-visible:ring-2 focus-visible:ring-rt-accent/60 {{ $mine ? 'justify-end' : 'justify-start' }}"
                x-on:contextmenu.prevent="openActions($event, {{ $message->id }}, @js($mine), true)"
                x-on:keydown.shift.f10.prevent="openActions($event, {{ $message->id }}, @js($mine))"
                x-on:keydown.context-menu.prevent="openActions($event, {{ $message->id }}, @js($mine))"
                x-on:pointerdown="startPress($event, {{ $message->id }}, @js($mine))"
                x-on:pointermove="movePress($event)"
                x-on:pointerup="finishPress($event)"
                x-on:pointerleave="cancelPress()"
            >
                @unless ($mine)
                    <x-chat.avatar :src="$message->sender?->profile_photo_url" :name="$message->sender?->name ?? '?'" size="xs" />
                @endunless

                <div class="max-w-[86%] sm:max-w-[78%]">
                    <div class="flex items-start gap-1 {{ $mine ? 'flex-row-reverse' : '' }}">
                        <div @class([
                            'min-w-0 rounded-2xl px-3.5 py-2.5 shadow-sm',
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
                                        @elseif ($reply->isVoice())
                                            {{ __('app.voice_message') }}
                                        @elseif ($reply->files->isNotEmpty() && blank($reply->body))
                                            {{ __('app.file') }}: {{ $reply->files->first()->name }}
                                        @else
                                            {{ \Illuminate\Support\Str::limit($reply->body, 100) }}
                                        @endif
                                    </span>
                                </button>
                            @endif

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

                            <p class="mt-1 text-right text-[9px] font-semibold opacity-60">{{ $message->created_at?->format('H:i') }}</p>
                        </div>

                        <button
                            type="button"
                            x-on:click.stop="openActions($event, {{ $message->id }}, @js($mine))"
                            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted opacity-100 transition hover:bg-rt-surface-muted hover:text-rt-text focus-visible:opacity-100 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text sm:opacity-0 sm:group-hover:opacity-100"
                            aria-label="{{ __('app.actions') }}"
                        >
                            <i class="far fa-ellipsis" aria-hidden="true"></i>
                        </button>
                    </div>

                    @if ($reactionGroups->isNotEmpty())
                        <div class="mt-1 flex flex-wrap gap-1 {{ $mine ? 'justify-end' : 'justify-start' }}">
                            @foreach ($reactionGroups as $emoji => $reactions)
                                @php $reacted = $reactions->contains('user_id', auth()->id()); @endphp
                                <button
                                    type="button"
                                    wire:click="react({{ $message->id }}, @js($emoji))"
                                    @class([
                                        'inline-flex min-h-8 items-center gap-1 rounded-full px-2 text-xs font-bold ring-1 transition',
                                        'bg-rt-accent-soft text-rt-accent ring-rt-accent/30 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent' => $reacted,
                                        'bg-rt-surface text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/70' => ! $reacted,
                                    ])
                                    title="{{ $reactions->pluck('user.name')->filter()->join(', ') }}"
                                >
                                    <span>{{ $emoji }}</span>
                                    <span>{{ $reactions->count() }}</span>
                                </button>
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
                            {{ $replyingTo->view_once ? __('app.chat_view_once_voice_message') : (filled($replyingTo->body) ? \Illuminate\Support\Str::limit($replyingTo->body, 100) : __('app.file')) }}
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

    <div
        x-cloak
        x-show="actionOpen"
        x-transition.opacity.duration.120ms
        x-on:click.outside="actionOpen = false"
        class="fixed z-[260] w-72 max-w-[calc(100vw-1rem)] rounded-2xl bg-rt-surface p-2 shadow-2xl ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
        :style="`left:${actionX}px; top:${actionY}px`"
        role="menu"
        tabindex="-1"
        x-ref="actionMenu"
    >
        <div class="grid grid-cols-7 gap-1 border-b border-rt-border/60 pb-2 dark:border-rt-dark-border/60">
            @foreach ($quickReactions as $emoji)
                <button
                    type="button"
                    x-on:click="$wire.react(actionMessageId, @js($emoji)); actionOpen = false"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-xl transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted"
                    aria-label="{{ __('app.chat_react_with', ['emoji' => $emoji]) }}"
                >{{ $emoji }}</button>
            @endforeach
            <button type="button" x-on:click="expandedReactions = !expandedReactions" class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted" aria-label="{{ __('app.chat_more_reactions') }}">
                <i class="far fa-plus" aria-hidden="true"></i>
            </button>
        </div>

        <div x-show="expandedReactions" class="grid max-h-44 grid-cols-7 gap-1 overflow-y-auto py-2">
            @foreach ($allReactions as $emoji)
                <button type="button" x-on:click="$wire.react(actionMessageId, @js($emoji)); actionOpen = false" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-lg transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted">{{ $emoji }}</button>
            @endforeach
        </div>

        <div class="space-y-1 pt-2">
            <button type="button" x-on:click="$wire.startReply(actionMessageId); actionOpen = false" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted" role="menuitem">
                <i class="far fa-reply w-5 text-center" aria-hidden="true"></i>
                {{ __('app.chat_reply') }}
            </button>
            <button x-show="actionMine" type="button" x-on:click="$wire.deleteMessage(actionMessageId); actionOpen = false" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-semibold text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400" role="menuitem">
                <i class="far fa-trash w-5 text-center" aria-hidden="true"></i>
                {{ __('app.delete') }}
            </button>
        </div>
    </div>
</div>
