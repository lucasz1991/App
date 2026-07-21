@props([
    'message',
    'file',
    'own' => false,
    'consumed' => false,
])

@php
    $sourceUrl = $message->view_once ? '' : route('chat.attachments', ['file' => $file]);
    $consumedLabel = $own && $message->view_once
        ? __('app.voice_once_sent')
        : __('app.voice_once_consumed');
@endphp

<div
    x-data="chatAudioPlayer({
        messageId: {{ (int) $message->id }},
        sourceUrl: @js($sourceUrl),
        viewOnce: @js((bool) $message->view_once),
        consumed: @js((bool) $consumed)
    })"
    x-on:chat:voice-ready.window="acceptSource($event.detail)"
    x-on:chat:voice-consumed.window="markConsumed($event.detail)"
    data-no-chat-swipe
    class="rt-voice-message w-[min(19rem,74vw)] max-w-full py-0.5"
>
    <audio
        x-ref="audio"
        x-bind:src="sourceUrl || null"
        preload="metadata"
        class="sr-only"
        @loadedmetadata="metadataLoaded()"
        @timeupdate="timeUpdated()"
        @play="playing = true"
        @pause="playing = false"
        @ended="ended()"
    ></audio>

    <div x-show="consumed" class="flex min-h-12 items-center gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-black/10 dark:bg-white/10">
            <i class="far fa-circle-check" aria-hidden="true"></i>
        </span>
        <span class="min-w-0">
            <span class="block text-xs font-semibold">{{ $consumedLabel }}</span>
            <span class="mt-0.5 block text-[10px] opacity-70">{{ __('app.voice_once_unavailable') }}</span>
        </span>
    </div>

    <div x-show="!consumed" class="flex min-w-0 items-center gap-2.5">
        <button
            type="button"
            @click="toggle()"
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-black/10 text-sm transition duration-200 hover:bg-black/15 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50 dark:bg-white/10 dark:hover:bg-white/15"
            aria-label="{{ __('app.play_voice_message') }}"
        >
            <i x-show="loading" class="fas fa-spinner fa-spin" aria-hidden="true"></i>
            <i x-show="!loading" :class="playing ? 'fas fa-pause' : 'fas fa-play pl-0.5'" aria-hidden="true"></i>
        </button>

        <div class="min-w-0 flex-1">
            <div class="mb-1 flex items-center gap-2 text-[10px] font-medium opacity-80">
                <span class="truncate">{{ $message->view_once ? __('app.voice_once') : __('app.voice_message') }}</span>
                @if ($message->view_once)
                    <span class="rt-voice-once-badge" title="{{ __('app.voice_once_hint') }}">1</span>
                @endif
                <span class="ml-auto shrink-0 tabular-nums" x-text="formattedTime"></span>
            </div>

            <div class="rt-voice-waveform relative h-7">
                <div class="flex h-full items-center gap-[2px] overflow-hidden" aria-hidden="true">
                    <template x-for="(height, index) in waveform" :key="index">
                        <span
                            class="block w-[2px] shrink-0 rounded-full bg-current opacity-30 transition-opacity duration-150"
                            :class="((index + 1) / waveform.length) * 100 <= progress ? '!opacity-100' : ''"
                            :style="`height: ${height}px`"
                        ></span>
                    </template>
                </div>
                <input
                    type="range"
                    min="0"
                    step="0.1"
                    :max="duration || 0"
                    :value="currentTime"
                    @input="seek($event.target.value)"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    aria-label="{{ __('app.voice_message_progress') }}"
                >
            </div>
        </div>

        <i class="far fa-microphone-lines shrink-0 text-xs opacity-55" aria-hidden="true"></i>
    </div>
</div>
