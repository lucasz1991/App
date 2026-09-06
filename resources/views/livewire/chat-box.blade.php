<div
    class="rt-chat-page relative h-full min-h-0 overflow-hidden"
    x-data="chatPaneNavigation(@js((bool) $selectedChat))"
    data-has-selected-chat="{{ $selectedChat ? 'true' : 'false' }}"
    data-active-chat-id="{{ $selectedChat?->id ?? 0 }}"
    data-mobile-pane="{{ $selectedChat ? 'chat' : 'list' }}"
    data-keyboard-open="false"
    data-topbar-inset="70"
    data-no-sidebar-swipe
    x-bind:data-mobile-pane="mobilePane"
    x-bind:data-keyboard-open="keyboardOpen ? 'true' : 'false'"
    x-bind:data-topbar-inset="Math.round(topbarInset)"
    x-bind:style="`--rt-chat-visual-height: ${Math.round(visualViewportHeight)}px; --rt-chat-visual-top: ${Math.round(visualViewportTop)}px; --rt-chat-topbar-inset: ${Math.round(topbarInset)}px;`"
    x-on:chat:pane-open.window="showChat()"
    x-on:chat:pane-list.window="showList()"
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
    @include('livewire.chat.partials.chat-options-modals')
    <livewire:people.person-preview-modal :key="'chat-person-preview-modal'" />

    {{-- Traegt die Schaltflaeche "Nachricht senden" aus den Personenkarten. --}}
    @can('users.messages.create')
        <livewire:admin.users.messages.message-form :key="'chat-message-form'" />
    @endcan
</div>
