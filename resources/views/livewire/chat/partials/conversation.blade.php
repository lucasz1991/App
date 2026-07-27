<div class="rt-chat-conversation-pane flex min-h-0 min-w-0 flex-1 flex-col" data-anim="fade">
    @if (! $selectedChat)
        <div class="rt-chat-conversation-empty relative min-h-0 flex-1">
            <button
                type="button"
                x-on:click="toggleList()"
                class="rt-chat-icon-button rt-chat-icon-button--quiet absolute left-4 top-4 z-10 hidden h-10 w-10 items-center justify-center rounded-xl text-sm md:inline-flex"
                data-chat-list-toggle
                aria-controls="rt-chat-overview"
                x-bind:aria-expanded="(! listCollapsed).toString()"
                x-bind:class="listCollapsed ? 'rt-chat-icon-button--open' : ''"
                x-bind:aria-label="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
                x-bind:title="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
            >
                <i class="far fa-chevron-left" aria-hidden="true"></i>
            </button>

            <x-chat.empty-state
                :title="__('app.chat_and_messages')"
                :description="__('app.no_chat_selected')"
            >
                <button
                    type="button"
                    wire:click="$set('showNewChat', true)"
                    class="rt-chat-text-action mt-5"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                    {{ __('app.new_chat') }}
                </button>
            </x-chat.empty-state>
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
            @include('livewire.chat.partials.conversation-header')
            @include('livewire.chat.partials.transcript')
            @include('livewire.chat.partials.composer')
        </div>
    @endif
</div>
