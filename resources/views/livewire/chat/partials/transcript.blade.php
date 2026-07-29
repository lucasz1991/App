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
            $showSender = $selectedChat->isGroup() && ! $own;
            $isRead = $own && $selectedChat->messageReadByAllRecipients($message, $me);
            $voiceFile = $message->voiceFile();
            $voiceConsumed = $message->view_once
                && ($own || ($message->hasBeenViewedBy($me) && ! $message->hasActiveVoicePlaybackFor($me)));
            $messageSurface = $voiceFile
                ? 'rt-chat-message--voice'
                : ($message->files->isNotEmpty() ? 'rt-chat-message--attachment' : 'rt-chat-message--text');
        @endphp

        <div wire:key="chat-msg-{{ $message->id }}" data-chat-message-row class="rt-chat-message-row flex w-full flex-col">
            @if ($newDay)
                <div class="flex justify-center py-3">
                    <span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">
                        {{ $chip }}
                    </span>
                </div>
            @endif

            <div
                class="rt-chat-message-line {{ $own ? 'rt-chat-message-line--own ml-auto flex-row-reverse' : 'mr-auto' }} flex max-w-full items-end gap-2"
            >
                <x-chat.avatar
                    :src="$message->sender?->profile_photo_url"
                    :name="$message->sender?->name ?? __('app.unknown')"
                    size="xs"
                    class="rt-chat-message-avatar mb-0.5"
                />

                @php
                    // Textnachricht ohne Anhaenge: Zeit + Haken fliessen als
                    // Inline-Element in die letzte Textzeile (WhatsApp-Stil)
                    // statt eine eigene Zeile zu belegen.
                    $metaInline = filled($message->body) && ! $voiceFile && $message->files->isEmpty();
                @endphp
                <div
                    data-rt-chat-message="{{ $own ? 'own' : 'other' }}"
                    @if ($own)
                        tabindex="0"
                    @endif
                    class="rt-chat-message {{ $own
                        ? 'rt-chat-message--own rt-chat-message--actionable rounded-br-md'
                        : 'rt-chat-message--other rounded-bl-md' }} {{ $messageSurface }} relative max-w-[90%] rounded-[1.15rem] px-3.5 py-2.5 text-[13px] leading-5 sm:max-w-[min(72vw,38rem)] sm:px-4"
                >
                    @if ($own)
                        {{-- Loeschen wandert aus der Meta-Zeile in ein Caret-Menue
                             oben rechts — sichtbar bei Hover/Fokus, auf Touch dezent
                             permanent (CSS .rt-chat-message-actions). --}}
                        <div class="rt-chat-message-actions" data-no-chat-swipe>
                            <x-ui.dropdown.anchor-dropdown align="right" width="max" :offset="4">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="rt-chat-message-caret flex h-6 w-6 items-center justify-center rounded-full text-[10px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                        title="{{ __('app.message_options') }}"
                                        aria-label="{{ __('app.message_options') }}"
                                    >
                                        <i class="far fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="space-y-0.5 p-1.5">
                                        <x-ui.dropdown.dropdown-link wire:click="requestDeleteMessage({{ $message->id }})" data-rt-tone="danger">
                                            <span class="rt-chat-option-icon"><i class="far fa-trash-alt" aria-hidden="true"></i></span>
                                            <span>{{ __('app.delete_chat_message') }}</span>
                                        </x-ui.dropdown.dropdown-link>
                                    </div>
                                </x-slot>
                            </x-ui.dropdown.anchor-dropdown>
                        </div>
                    @endif

                    @if ($showSender)
                        <p class="rt-chat-message-sender mb-1 text-[10px] font-extrabold tracking-[0.01em]">
                            {{ $message->sender?->name }}
                        </p>
                    @endif

                    @if (filled($message->body))
                        <p class="rt-chat-message-copy whitespace-pre-wrap break-words">{{ $message->body }}@if ($metaInline)<span class="rt-chat-message-meta-inline"><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('H:i') }}</time>@if ($own)<i class="rt-chat-read-indicator far fa-check-double {{ $isRead ? 'is-read' : 'is-delivered' }}" title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}" aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"></i>@endif</span>@endif</p>
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
                                    $mime = strtolower((string) $file->mime_type);
                                    $isImage = str_starts_with($mime, 'image/');
                                    $isVideo = str_starts_with($mime, 'video/');
                                @endphp

                                <button
                                    type="button"
                                    @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                                    data-no-chat-swipe
                                    class="{{ $isImage || $isVideo ? 'rt-chat-image-preview group/image relative block overflow-hidden rounded-2xl bg-black/10' : 'rt-chat-file-card flex items-center gap-3 rounded-xl px-2.5 py-2' }} min-w-0 max-w-[72vw] text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 sm:max-w-full"
                                    title="{{ __('app.preview') }}"
                                    aria-label="{{ __('app.preview') }}: {{ $file->name }}"
                                >
                                    @if ($isImage)
                                        <img
                                            src="{{ $inlineUrl }}"
                                            alt="{{ $file->name }}"
                                            loading="lazy"
                                            class="max-h-80 w-auto max-w-full object-contain transition duration-500 ease-rt-spring group-hover/image:scale-[1.015]"
                                        >
                                    @elseif ($isVideo)
                                        <span class="relative flex min-h-36 w-[min(21rem,70vw)] items-center justify-center bg-black/85 text-white">
                                            <video
                                                src="{{ $inlineUrl }}"
                                                preload="metadata"
                                                muted
                                                playsinline
                                                aria-hidden="true"
                                                class="absolute inset-0 h-full w-full object-cover opacity-75"
                                            ></video>
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
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/15 backdrop-blur-md">
                                                <i class="far fa-expand" aria-hidden="true"></i>
                                            </span>
                                        </span>
                                    @else
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-70">
                                            <i class="far fa-eye" aria-hidden="true"></i>
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Medien/Voice: Zeit + Haken kompakt rechts unter dem
                         Inhalt — die fruehere Extrazeile samt Loeschen-Knopf
                         entfaellt (Loeschen liegt im Caret-Menue). --}}
                    @unless ($metaInline)
                        <div class="rt-chat-message-meta mt-1 flex items-center justify-end gap-1 text-right text-[9px] font-semibold leading-none">
                            <time datetime="{{ $message->created_at->toIso8601String() }}">
                                {{ $message->created_at->format('H:i') }}
                            </time>
                            @if ($own)
                                <i
                                    class="rt-chat-read-indicator far fa-check-double {{ $isRead ? 'is-read' : 'is-delivered' }}"
                                    title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"
                                    aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"
                                ></i>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>
        </div>
    @endforeach
</div>
