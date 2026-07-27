@php
    $headerAvatar = $selectedChat->avatarUrlFor($me);
@endphp

<div class="rt-chat-conversation-header flex shrink-0 items-center gap-2.5 px-3 py-3 sm:gap-3 sm:px-5 sm:py-3.5">
    <button
        type="button"
        x-on:click="toggleList()"
        class="rt-chat-icon-button rt-chat-icon-button--quiet hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm md:inline-flex"
        data-chat-list-toggle
        aria-controls="rt-chat-overview"
        x-bind:aria-expanded="(! listCollapsed).toString()"
        x-bind:class="listCollapsed ? 'rt-chat-icon-button--open' : ''"
        x-bind:aria-label="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
        x-bind:title="listCollapsed ? @js(__('app.show_chat_overview')) : @js(__('app.hide_chat_overview'))"
    >
        <i class="far fa-chevron-left" aria-hidden="true"></i>
    </button>

    <x-chat.icon-button
        icon="far fa-arrow-left"
        :label="__('app.back')"
        class="md:hidden"
        x-on:click="showList()"
    />

    <x-chat.avatar
        :src="$headerAvatar"
        :name="$selectedChat->displayNameFor($me)"
        size="lg"
        signal
    />

    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-extrabold tracking-[-0.025em] text-rt-text dark:text-rt-dark-text sm:text-[15px]">
            {{ $selectedChat->displayNameFor($me) }}
        </p>
        @if ($selectedChat->isGroup())
            <p class="mt-0.5 truncate text-[10px] font-medium uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                {{ trans_choice('app.members_count', $selectedChat->participants->count()) }}
            </p>
        @endif
        <p
            x-cloak
            x-show="typingLabel"
            x-text="typingLabel"
            class="rt-chat-typing mt-0.5 truncate text-[10px] font-bold text-rt-accent dark:text-rt-dark-accent"
        ></p>
    </div>

    <span class="rt-chat-live-status hidden items-center gap-2 rounded-full px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.1em] sm:inline-flex">
        <span aria-hidden="true"></span>
        {{ __('app.online') }}
    </span>
</div>
