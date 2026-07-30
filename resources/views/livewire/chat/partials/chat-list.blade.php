<div
    id="rt-chat-overview"
    class="rt-chat-list-pane flex min-h-0 w-full shrink-0 flex-col overflow-hidden md:w-[21.5rem]"
    data-anim="left"
    x-bind:class="{ 'rt-chat-list-collapsed': listCollapsed }"
>
    <div class="rt-chat-list-header shrink-0 px-4 pb-3 pt-4 sm:px-5 sm:pt-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="rt-chat-kicker">{{ __('app.chat_and_messages') }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <h1 class="truncate text-xl font-extrabold tracking-[-0.04em] text-rt-text dark:text-white">
                        {{ __('app.chats') }}
                    </h1>
                    <span class="rt-chat-count" aria-label="{{ $chats->count() }} {{ __('app.chats') }}">
                        {{ $chats->count() }}
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-1.5">
                <x-ui.page-info-button :title="__('app.chats')" route-name="chat" />
                <x-chat.icon-button
                    icon="far fa-chevron-left"
                    :label="__('app.hide_chat_overview')"
                    size="sm"
                    class="hidden md:inline-flex"
                    x-on:click="toggleList()"
                    aria-controls="rt-chat-overview"
                />
                <x-chat.icon-button
                    icon="far fa-plus"
                    :label="__('app.new_chat')"
                    tone="accent"
                    wire:click="$set('showNewChat', true)"
                />
            </div>
        </div>
    </div>

    <div class="shrink-0 px-3 pb-3 sm:px-4">
        <label for="chat-search" class="sr-only">{{ __('app.search') }}</label>
        <div class="rt-chat-search relative">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <i class="far fa-search text-xs" aria-hidden="true"></i>
            </div>
            <input
                type="search"
                id="chat-search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('app.search') }}"
                autocomplete="off"
                class="h-11 w-full rounded-[0.9rem] border-0 bg-transparent pl-9 pr-3 text-sm outline-none ring-0 placeholder:text-rt-soft focus:border-0 focus:ring-0 dark:text-white dark:placeholder:text-rt-dark-soft"
            >
        </div>
    </div>

    <div class="rt-chat-list min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-2 pb-3 sm:px-3">
        @forelse ($chats as $chat)
            @php
                $isActive = (int) $selectedChatId === (int) $chat->id;
                $unread = $chat->unreadCountFor($me);
                $latest = $chat->latestMessage;
                $avatarUrl = $chat->avatarUrlFor($me);
                $previewPerson = $chat->isGroup()
                    ? null
                    : $chat->participants->firstWhere('id', '!=', $me->id);
                $timeLabel = $latest
                    ? ($latest->created_at->isToday() ? $latest->created_at->format('H:i') : $latest->created_at->format('d.m.'))
                    : '';
            @endphp

            <div
                wire:key="chat-item-{{ $chat->id }}"
                class="rt-chat-list-entry group relative"
            >
                <button
                    type="button"
                    wire:click="openChat({{ $chat->id }})"
                    x-on:click="showChat()"
                    data-chat-list-item
                    @if ($isActive) aria-current="page" @endif
                    class="rt-chat-list-item {{ $isActive ? 'is-active' : '' }} flex w-full items-center gap-3 rounded-2xl py-2.5 pl-2.5 pr-12 text-left sm:pl-3"
                >
                    <x-chat.avatar
                        :src="$avatarUrl"
                        :name="$chat->displayNameFor($me)"
                        :signal="$isActive"
                    />

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
                            {{ $chat->displayNameFor($me) }}
                        </span>
                        <span class="mt-0.5 block truncate text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">
                            @if ($latest)
                                {{ (int) $latest->user_id === (int) $me->id ? __('app.you') . ': ' : '' }}{{ $latest->isVoice() ? __('app.voice_message') : (filled($latest->body) ? $latest->body : __('app.chat_attachment')) }}
                            @endif
                        </span>
                    </span>

                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="text-[9px] font-semibold tabular-nums text-rt-soft dark:text-rt-dark-soft">{{ $timeLabel }}</span>
                        @if ($unread > 0)
                            <span class="rt-chat-unread min-w-5 rounded-full px-1.5 py-1 text-center text-[9px] font-extrabold leading-none text-white">
                                {{ $unread }}
                            </span>
                        @endif
                    </span>
                </button>

                <div class="rt-chat-list-options absolute right-1.5 top-1/2 z-[2] -translate-y-1/2">
                    <x-ui.dropdown.anchor-dropdown
                        align="right"
                        width="56"
                        :offset="6"
                    >
                        <x-slot name="trigger">
                            <x-ui.dropdown.action-trigger
                                orientation="vertical"
                                :aria-label="__('app.chat_options') . ': ' . $chat->displayNameFor($me)"
                                class="rt-chat-list-options-trigger h-9 w-9 rounded-xl border-0 bg-transparent px-0 shadow-none"
                                data-no-chat-swipe
                            />
                        </x-slot>

                        <x-slot name="content">
                            <div class="space-y-0.5 p-1.5" aria-label="{{ __('app.chat_options') }}">
                                @if ($previewPerson)
                                    <x-ui.dropdown.dropdown-link
                                        wire:click="$dispatch('person-preview:open', { userId: {{ $previewPerson->id }} })"
                                        data-no-chat-swipe
                                    >
                                        <span class="rt-chat-option-icon">
                                            <i class="far fa-address-card" aria-hidden="true"></i>
                                        </span>
                                        <span>{{ __('app.person_preview') }}</span>
                                    </x-ui.dropdown.dropdown-link>
                                @endif

                                <x-ui.dropdown.dropdown-link
                                    as="a"
                                    :href="route('chat.export', ['chat' => $chat])"
                                    data-no-chat-swipe
                                >
                                    <span class="rt-chat-option-icon">
                                        <i class="far fa-file-export" aria-hidden="true"></i>
                                    </span>
                                    <span>{{ __('app.export_chat') }}</span>
                                </x-ui.dropdown.dropdown-link>

                                <div class="rt-chat-option-divider my-1" role="separator"></div>

                                <x-ui.dropdown.dropdown-link
                                    wire:click="requestDeleteChat({{ $chat->id }})"
                                    data-rt-tone="danger"
                                    data-no-chat-swipe
                                >
                                    <span class="rt-chat-option-icon">
                                        <i class="far {{ $chat->isGroup() && ! $chat->canManageGroup($me) ? 'fa-sign-out-alt' : 'fa-trash-alt' }}" aria-hidden="true"></i>
                                    </span>
                                    <span>
                                        @if ($chat->isGroup() && ! $chat->canManageGroup($me))
                                            {{ __('app.leave_group') }}
                                        @elseif ($chat->isGroup())
                                            {{ __('app.delete_group') }}
                                        @else
                                            {{ __('app.delete_chat') }}
                                        @endif
                                    </span>
                                </x-ui.dropdown.dropdown-link>
                            </div>
                        </x-slot>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
            </div>
        @empty
            <x-chat.empty-state
                compact
                :title="__('app.chats')"
                :description="__('app.no_chats_yet')"
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
        @endforelse
    </div>
</div>
