<div
    class="rt-chat-composer shrink-0 px-2 pb-2.5 pt-2 sm:px-3 sm:pb-3"
    data-no-chat-swipe
    wire:key="chat-composer-{{ $selectedChat->id }}"
>
    @if ($uploads !== [])
        <div class="mb-2 flex flex-wrap gap-2 px-1" aria-live="polite">
            @foreach ($uploads as $index => $upload)
                <span wire:key="chat-upload-{{ $index }}" class="rt-chat-upload-chip inline-flex max-w-full items-center gap-2 rounded-xl px-2.5 py-1.5 text-[11px] font-semibold text-rt-text dark:text-white">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg">
                        <i class="far fa-paperclip" aria-hidden="true"></i>
                    </span>
                    <span class="max-w-48 truncate">{{ $upload->getClientOriginalName() }}</span>
                    <button
                        type="button"
                        wire:click="removeUpload({{ $index }})"
                        class="rt-chat-upload-remove inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                        aria-label="{{ __('app.remove') }}"
                    >
                        <i class="far fa-times" aria-hidden="true"></i>
                    </button>
                </span>
            @endforeach
        </div>
    @endif

    <form
        wire:submit.prevent="send"
        x-data="{ draft: @entangle('messageText') }"
        class="flex items-center"
    >
        <input
            id="chat-attachments-{{ $selectedChat->id }}"
            type="file"
            wire:model="uploads"
            multiple
            accept="audio/*,video/*,image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
            class="sr-only"
        >

        <div
            x-show.important="!recording && !sendingVoice"
            data-chat-composer-mode="text"
            class="rt-chat-composer-control flex min-h-12 min-w-0 flex-1 items-center gap-1 rounded-[1.15rem] px-1.5 py-1"
        >
            <label
                for="chat-attachments-{{ $selectedChat->id }}"
                title="{{ __('app.add_attachment') }}"
                class="rt-chat-composer-action flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-xl"
            >
                <i class="far fa-paperclip" aria-hidden="true"></i>
                <span class="sr-only">{{ __('app.add_attachment') }}</span>
            </label>

            <input
                type="text"
                x-model="draft"
                @input.debounce.250ms="sendTyping()"
                placeholder="{{ __('app.type_message') }}"
                autocomplete="off"
                autocapitalize="sentences"
                enterkeyhint="send"
                spellcheck="true"
                class="h-10 min-w-0 flex-1 border-0 bg-transparent px-2 text-base leading-6 text-rt-text outline-none placeholder:text-rt-soft focus:border-0 focus:ring-0 sm:text-sm dark:text-white dark:placeholder:text-rt-dark-soft"
            >

            <button
                x-cloak
                x-show.important="draft.trim().length === 0 && !@js($uploads !== [])"
                type="button"
                @click="startRecording()"
                title="{{ __('app.voice_message') }}"
                class="rt-chat-composer-action flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
            >
                <i class="far fa-microphone" aria-hidden="true"></i>
                <span class="sr-only">{{ __('app.voice_message') }}</span>
            </button>

            <button
                x-cloak
                x-show="draft.trim().length > 0 || @js($uploads !== [])"
                type="submit"
                title="{{ __('app.type_message') }}"
                class="rt-chat-send-button flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"
            >
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
            class="rt-voice-recorder flex min-w-0 flex-1 items-center gap-1.5 rounded-[1.15rem] px-1.5 py-1 sm:gap-2"
        >
            <button
                type="button"
                @click="cancelRecording()"
                :disabled="sendingVoice"
                class="rt-chat-composer-action flex h-9 w-9 shrink-0 items-center justify-center rounded-xl disabled:cursor-not-allowed disabled:opacity-40"
                title="{{ __('app.cancel_recording') }}"
                aria-label="{{ __('app.cancel_recording') }}"
            >
                <i class="far fa-trash-alt" aria-hidden="true"></i>
            </button>

            <div class="flex min-w-0 flex-1 items-center gap-1.5 sm:gap-2">
                <span class="rt-recording-dot h-2.5 w-2.5 shrink-0 rounded-full" aria-hidden="true"></span>
                <span class="sr-only">{{ __('app.recording') }}</span>
                <span class="w-[3.4rem] shrink-0 truncate text-[10px] font-extrabold tabular-nums text-rt-text dark:text-white sm:w-[4.75rem] sm:text-xs" x-text="sendingVoice ? @js(__('app.voice_sending')) : recordingLabel"></span>
                <div class="rt-recording-wave flex h-7 min-w-0 flex-1 items-center justify-center gap-[2px] overflow-hidden sm:gap-[3px]" aria-hidden="true">
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
                :class="viewOnce ? 'is-active' : ''"
                class="rt-voice-once-button flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-xs font-extrabold disabled:cursor-not-allowed disabled:opacity-40"
                title="{{ __('app.voice_once_hint') }}"
                aria-label="{{ __('app.voice_once') }}"
            >1</button>

            <button
                type="button"
                @click="sendRecording()"
                :disabled="sendingVoice"
                class="rt-chat-send-button flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white disabled:cursor-wait disabled:opacity-70"
                title="{{ __('app.send_voice_message') }}"
                aria-label="{{ __('app.send_voice_message') }}"
            >
                <i :class="sendingVoice ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" aria-hidden="true"></i>
            </button>
        </div>
    </form>

    @error('messageText')
        <p class="rt-chat-field-error mt-1.5 px-2 text-xs">{{ $message }}</p>
    @enderror
    @error('uploads')
        <p class="rt-chat-field-error mt-1.5 px-2 text-xs">{{ $message }}</p>
    @enderror
    @error('uploads.*')
        <p class="rt-chat-field-error mt-1.5 px-2 text-xs">{{ $message }}</p>
    @enderror
    @error('voiceUpload')
        <p class="rt-chat-field-error mt-1.5 px-2 text-xs">{{ $message }}</p>
    @enderror
    <p wire:loading wire:target="uploads" class="mt-1.5 px-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.uploading') }}</p>
</div>
