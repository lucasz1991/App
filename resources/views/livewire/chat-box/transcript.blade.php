{{--
    Chat: Verlauf des offenen Gespraechs (Polling, aeltere Nachrichten, Tipp-Anzeige).
    Eigener Scroll-Scope innerhalb des Echtzeit-Scopes.
    Erwartet: $selectedChat, $messages, $hasOlderMessages, $me; reicht $items, $index und $message weiter.
--}}
{{-- chat-section:transcript:start --}}
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
        @if($message->isCall())
            @include('livewire.chat-box.call-event')
        @else
        @include('livewire.chat-box.message')
        @endif
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
{{-- chat-section:transcript:end --}}
