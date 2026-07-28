<div
    wire:poll.visible.2s="pollTick"
    wire:key="chat-pane-{{ $selectedChat->id }}"
    x-data="chatTranscriptScroll()"
    x-on:chat:scroll-bottom.window="$nextTick(() => scrollToLatest(true))"
    class="rt-chat-transcript min-h-0 flex-1 space-y-1.5 overflow-y-auto overscroll-contain px-2.5 py-4 sm:px-5 sm:py-6 lg:px-8"
>
    @php
        $items = $messages->values();
    @endphp

    @foreach ($items as $index => $message)
        @php
            $prev = $index > 0 ? $items[$index - 1] : null;
            $own = (int) $message->user_id === (int) $me->id;
            $newDay = ! $prev || ! $prev->created_at->isSameDay($message->created_at);
            $chip = $message->created_at->isToday()
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

        <div wire:key="chat-msg-{{ $message->id }}" class="rt-chat-message-row flex w-full flex-col">
            @if ($newDay)
                <div class="flex justify-center py-3">
                    <span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">
                        {{ $chip }}
                    </span>
                </div>
            @endif

            @if ($showSender)
                <p class="mb-1 mr-auto max-w-[75%] px-2 text-[10px] font-bold tracking-[0.01em] text-rt-accent dark:text-rt-dark-accent">
                    {{ $message->sender?->name }}
                </p>
            @endif

            <div
                data-rt-chat-message="{{ $own ? 'own' : 'other' }}"
                class="rt-chat-message {{ $own
                    ? 'rt-chat-message--own ml-auto rounded-br-md'
                    : 'rt-chat-message--other mr-auto rounded-bl-md' }} max-w-[91%] rounded-[1.15rem] px-3.5 py-2.5 text-[13px] leading-5 sm:max-w-[76%] sm:px-4"
            >
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
                                <button
                                    type="button"
                                    @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                                    data-no-chat-swipe
                                    class="rt-chat-image-preview group/image relative block max-w-[76vw] overflow-hidden rounded-2xl bg-black/10 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 sm:max-w-full"
                                    title="{{ __('app.preview') }}"
                                    aria-label="{{ __('app.preview') }}: {{ $file->name }}"
                                >
                                    <img
                                        src="{{ $inlineUrl }}"
                                        alt="{{ $file->name }}"
                                        loading="lazy"
                                        class="max-h-80 w-auto max-w-full object-contain transition duration-500 ease-rt-spring group-hover/image:scale-[1.015]"
                                    >
                                    <span class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 bg-gradient-to-t from-black/85 via-black/35 to-transparent px-3 pb-2.5 pt-10 text-[10px] font-semibold text-white">
                                        <span class="truncate">{{ $file->name }}</span>
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/15 backdrop-blur-md">
                                            <i class="far fa-expand" aria-hidden="true"></i>
                                        </span>
                                    </span>
                                </button>
                            @elseif (str_starts_with($mime, 'audio/'))
                                <div x-data="chatAudioPlayer()" data-no-chat-swipe class="rt-chat-audio-card w-[min(18rem,76vw)] max-w-full py-0.5">
                                    <audio
                                        x-ref="audio"
                                        preload="metadata"
                                        class="sr-only"
                                        src="{{ $inlineUrl }}"
                                        @loadedmetadata="metadataLoaded()"
                                        @timeupdate="timeUpdated()"
                                        @play="playing = true"
                                        @pause="playing = false"
                                        @ended="ended()"
                                    >
                                        <a href="{{ $downloadUrl }}">{{ $file->name }}</a>
                                    </audio>

                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <button
                                            type="button"
                                            @click="toggle()"
                                            class="rt-chat-media-button flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                            aria-label="{{ __('app.voice_message') }}"
                                        >
                                            <i :class="playing ? 'fas fa-pause' : 'fas fa-play pl-0.5'" aria-hidden="true"></i>
                                        </button>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 text-[10px] font-medium opacity-80">
                                                <span class="truncate">{{ __('app.voice_message') }}</span>
                                                <span class="ml-auto shrink-0 tabular-nums" x-text="formattedTime"></span>
                                            </div>
                                            <input
                                                type="range"
                                                min="0"
                                                step="0.1"
                                                :max="duration || 0"
                                                :value="currentTime"
                                                :style="`--rt-voice-progress: ${progress}%`"
                                                @input="seek($event.target.value)"
                                                class="rt-voice-progress mt-1 block w-full cursor-pointer"
                                                aria-label="{{ __('app.voice_message') }}"
                                            >
                                        </div>

                                        <a
                                            href="{{ $downloadUrl }}"
                                            class="rt-chat-media-button flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-75 hover:opacity-100"
                                            title="{{ __('app.download') }}"
                                            aria-label="{{ __('app.download') }}"
                                        >
                                            <i class="far fa-download" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            @elseif (str_starts_with($mime, 'video/'))
                                <video
                                    controls
                                    preload="metadata"
                                    data-no-chat-swipe
                                    class="rt-chat-video max-h-80 w-full max-w-[76vw] rounded-2xl bg-black sm:max-w-full"
                                    src="{{ $inlineUrl }}"
                                >
                                    <a href="{{ $downloadUrl }}">{{ $file->name }}</a>
                                </video>
                            @else
                                <a
                                    href="{{ $downloadUrl }}"
                                    data-no-chat-swipe
                                    class="rt-chat-file-card flex min-w-0 max-w-[76vw] items-center gap-3 rounded-xl px-2.5 py-2 text-left sm:max-w-full"
                                >
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-black/10 dark:bg-white/10">
                                        <i class="far fa-file-download" aria-hidden="true"></i>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-xs font-semibold">{{ $file->name }}</span>
                                        <span class="block text-[10px] opacity-70">{{ $file->getMimeTypeForHumans() }}</span>
                                    </span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif

                <p class="rt-chat-message-meta mt-1.5 flex min-h-5 items-center justify-end gap-1.5 text-right text-[9px] font-semibold leading-none">
                    @if ($own)
                        <button
                            type="button"
                            wire:click="requestDeleteMessage({{ $message->id }})"
                            class="rt-chat-message-delete mr-auto inline-flex h-6 w-6 items-center justify-center rounded-lg opacity-55 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                            title="{{ __('app.delete_chat_message') }}"
                            aria-label="{{ __('app.delete_chat_message') }}"
                        >
                            <i class="far fa-trash-alt" aria-hidden="true"></i>
                        </button>
                    @endif
                    <span>{{ $message->created_at->format('H:i') }}</span>
                    @if ($own)
                        <i
                            class="rt-chat-read-indicator far fa-check-double {{ $isRead ? 'is-read' : 'is-delivered' }}"
                            title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"
                            aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"
                        ></i>
                    @endif
                </p>
            </div>
        </div>
    @endforeach
</div>
