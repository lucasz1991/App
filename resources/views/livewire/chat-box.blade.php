<div
    class="rt-chat-page relative h-full min-h-0 overflow-hidden px-3 pb-3 pt-4 sm:px-4 sm:pb-4 sm:pt-5 md:px-0 md:pb-5 md:pt-5"
    x-data="chatPaneNavigation(@js((bool) $selectedChat))"
    data-has-selected-chat="{{ $selectedChat ? 'true' : 'false' }}"
    data-mobile-pane="{{ $selectedChat ? 'chat' : 'list' }}"
    x-bind:data-mobile-pane="mobilePane"
    x-on:chat:pane-open.window="showChat()"
    x-on:touchstart.passive="touchStart($event)"
    x-on:touchend.passive="touchEnd($event)"
    x-on:touchcancel="cancelSwipe()"
    wire:loading.class="cursor-wait"
>
    @php
        $me = auth()->user();
    @endphp

    <div
        class="rt-chat-shell h-full min-h-0 overflow-hidden rounded-[1.35rem] bg-rt-surface shadow-rt-lg sm:rounded-[1.75rem] dark:bg-rt-dark-surface"
        data-anim="zoom"
        data-chat-redesign="vengeance"
    >
        <div class="rt-chat-panes relative flex h-full min-h-0 overflow-hidden">
            @include('livewire.chat.partials.chat-list')
            @include('livewire.chat.partials.conversation')
        </div>
    </div>

    @include('livewire.chat.partials.new-chat-modal')
</div>
