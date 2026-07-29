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
        consumed: @js((bool) $consumed),
        durationHint: {{ (int) ($message->voice_duration_seconds ?? 0) }}
    })"
    x-on:chat:voice-ready.window="acceptSource($event.detail)"
    x-on:chat:voice-consumed.window="markConsumed($event.detail)"
    x-bind:data-playing="playing.toString()"
    x-bind:data-loading="loading.toString()"
    x-bind:data-playback-blocked="playbackBlocked.toString()"
    data-no-chat-swipe
    class="rt-voice-message w-[min(20rem,78vw)] max-w-full"
>
    <audio
        x-ref="audio"
        x-bind:src="sourceUrl || null"
        wire:ignore
        playsinline
        preload="{{ $message->view_once ? 'none' : 'metadata' }}"
        class="sr-only"
        @loadedmetadata="metadataLoaded()"
        @timeupdate="timeUpdated()"
        @play="playbackStarted()"
        @pause="playbackPaused()"
        @ended="ended()"
    ></audio>

    <div x-show.important="consumed" class="rt-voice-consumed flex min-h-16 items-center gap-3 rounded-[1rem] px-3 py-2.5">
        <span class="rt-voice-consumed__icon flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
            <i class="far fa-circle-check" aria-hidden="true"></i>
        </span>
        <span class="min-w-0">
            <span class="block text-[0.72rem] font-bold tracking-[-0.015em]">{{ $consumedLabel }}</span>
            <span class="mt-0.5 block text-[0.625rem] leading-4 opacity-70">{{ __('app.voice_once_unavailable') }}</span>
        </span>
    </div>

    <div x-show.important="!consumed" class="rt-voice-player flex min-w-0 items-center gap-3">
        <button
            type="button"
            @click="toggle()"
            :aria-busy="loading.toString()"
            class="rt-voice-play flex h-[3.15rem] w-[3.15rem] shrink-0 items-center justify-center rounded-full text-base focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
            aria-label="{{ __('app.play_voice_message') }}"
        >
            <i x-show="loading" class="fas fa-spinner fa-spin" aria-hidden="true"></i>
            <i x-show="!loading" :class="playing ? 'fas fa-pause' : 'fas fa-play pl-0.5'" aria-hidden="true"></i>
        </button>

        <div class="rt-voice-content min-w-0 flex-1">
            <div class="rt-voice-heading flex items-center gap-1.5">
                <span class="rt-voice-title inline-flex min-w-0 items-center gap-1.5">
                    <i class="far fa-waveform shrink-0 text-[0.65rem]" aria-hidden="true"></i>
                    <span class="truncate">{{ $message->view_once ? __('app.voice_once') : __('app.voice_message') }}</span>
                </span>
                @if ($message->view_once)
                    <span class="rt-voice-once-badge" title="{{ __('app.voice_once_hint') }}">1</span>
                @endif
                <span class="rt-voice-duration ml-auto shrink-0 tabular-nums" x-text="formattedTime"></span>
            </div>

            <div
                class="rt-voice-timeline relative mt-1"
                :style="`--rt-voice-progress: ${progress}%`"
            >
                <div class="rt-voice-waveform flex h-8 items-center gap-[2px] overflow-hidden px-0.5" aria-hidden="true">
                    <template x-for="(height, index) in waveform" :key="index">
                        <span
                            class="block w-[2px] shrink-0 rounded-full bg-current transition-[opacity,transform] duration-150"
                            :class="((index + 1) / waveform.length) * 100 <= progress ? 'is-played' : ''"
                            :style="`height: ${height}px`"
                        ></span>
                    </template>
                </div>
                <span class="rt-voice-progress-rail" aria-hidden="true">
                    <span class="rt-voice-progress-fill"></span>
                    <span class="rt-voice-progress-thumb"></span>
                </span>
                <input
                    type="range"
                    min="0"
                    step="0.1"
                    :max="duration || 0"
                    :value="currentTime"
                    @input="seek($event.target.value)"
                    class="rt-voice-seek absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    aria-label="{{ __('app.voice_message_progress') }}"
                >
            </div>
        </div>
    </div>
</div>
