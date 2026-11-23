<div
    class="rt-chat-page relative h-full min-h-0 overflow-hidden"
    x-data="chatPaneNavigation"
    data-has-selected-chat="{{ $selectedChat ? 'true' : 'false' }}"
    data-active-chat-id="{{ $selectedChat?->id ?? 0 }}"
    data-mobile-pane="{{ $selectedChat ? 'chat' : 'list' }}"
    data-keyboard-open="false"
    data-topbar-inset="70"
    data-no-sidebar-swipe
    data-communication-chat="{{ $embedded ? 'true' : 'false' }}"
    x-bind:data-mobile-pane="mobilePane"
    x-bind:data-keyboard-open="keyboardOpen ? 'true' : 'false'"
    x-bind:data-topbar-inset="Math.round(topbarInset)"
    x-bind:style="`--rt-chat-visual-height: ${Math.round(visualViewportHeight)}px; --rt-chat-visual-top: ${Math.round(visualViewportTop)}px; --rt-chat-topbar-inset: ${Math.round(topbarInset)}px;`"
    x-on:chat:pane-open.window="showChat()"
    x-on:chat:pane-list.window="showList()"
    x-on:touchstart.passive="touchStart($event)"
    x-on:touchend.passive="touchEnd($event)"
    x-on:touchmove.passive="touchMove($event)"
    x-on:touchcancel="cancelSwipe()"
    @if($embedded) x-on:click.capture="consumeSwipeClick($event)" @endif
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
            {{-- chat-section:chat-list:start --}}
            <div
                id="rt-chat-overview"
                class="rt-chat-list-pane flex min-h-0 w-full shrink-0 flex-col overflow-hidden md:w-[21.5rem]"
                data-anim="left"
                @if($embedded)
                    x-bind:inert="!splitChat && mobilePane !== 'list'"
                @else
                    x-bind:class="{ 'rt-chat-list-collapsed': listCollapsed }"
                @endif
            >
                @if($embedded)
                    <x-ui.floating.toolbar label="Chats durchsuchen und filtern" search>
                        <x-ui.forms.search context="floating" id="communication-chat-search" wire:model.live.debounce.300ms="search" placeholder="Gespräche suchen …" />
                        <x-slot:actions>
                        <x-ui.dropdown.anchor-dropdown align="right" width="48" :offset="6" dropdown-id="communication-chat-filter" layer-group="communication-controls" content-label="Chats filtern">
                            <x-slot:trigger>
                                <button type="button" class="rt-floating-toolbar__button" aria-haspopup="menu" :aria-label="`Chats filtern: ${chatFilter === 'unread' ? 'Ungelesen' : (chatFilter === 'groups' ? 'Gruppen' : 'Alle')}`" title="Chats filtern">
                                    <i class="far fa-sliders-h" aria-hidden="true"></i>
                                </button>
                            </x-slot:trigger>
                            <x-slot:content>
                                @foreach(['all' => ['Alle', 'fa-comments'], 'unread' => ['Ungelesen', 'fa-envelope'], 'groups' => ['Gruppen', 'fa-users']] as $filter => [$label, $icon])
                                    <x-ui.dropdown.dropdown-link role="menuitemradio" :aria-checked="false" x-bind:aria-checked="(chatFilter === '{{ $filter }}').toString()" x-on:click="selectChatFilter('{{ $filter }}'); close()">
                                        <i class="far {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                                        <i class="far fa-check ml-auto" aria-hidden="true" x-show="chatFilter === '{{ $filter }}'"></i>
                                    </x-ui.dropdown.dropdown-link>
                                @endforeach
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>
                        <button type="button" wire:click="$set('showNewChat', true)" class="rt-floating-toolbar__button" aria-label="{{ __('app.new_chat') }}" title="{{ __('app.new_chat') }}"><i class="far fa-plus" aria-hidden="true"></i></button>
                        </x-slot:actions>
                    </x-ui.floating.toolbar>
                @else
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
                            <x-ui.buttons.button-basic
                                mode="icon"
                                tone="plain"
                                icon="far fa-chevron-left"
                                :label="__('app.hide_chat_overview')"
                                size="sm"
                                x-on:click="toggleList()"
                                aria-controls="rt-chat-overview"
                                class="rt-chat-icon-button rt-chat-icon-button--quiet rounded-xl text-sm hidden md:inline-flex"
                            />
                            <x-ui.buttons.button-basic
                                mode="icon"
                                tone="plain"
                                icon="far fa-plus"
                                :label="__('app.new_chat')"
                                wire:click="$set('showNewChat', true)"
                                class="rt-chat-icon-button rt-chat-icon-button--accent rounded-xl text-sm"
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

                @endif
                <div class="rt-chat-list rt-chat-stacked-list min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-2 pb-3 sm:px-3">
                    @forelse ($chats as $chat)
                        @php
                            $isActive = (int) $selectedChatId === (int) $chat->id;
                            $unread = $chat->unreadCountFor($me);
                            $latest = $chat->latestMessage;
                            $avatarUrl = $chat->avatarUrlFor($me);
                            $previewPerson = $chat->isGroup()
                                ? null
                                : $chat->participants->firstWhere('id', '!=', $me->id);
                            $previewPersonIsOnline = $previewPerson?->isOnline() ?? false;
                            $timeLabel = $latest
                                ? ($latest->created_at->isToday() ? $latest->created_at->format('H:i') : $latest->created_at->format('d.m.'))
                                : '';
                        @endphp
                        @continue($embedded && (($listFilter === 'unread' && $unread === 0) || ($listFilter === 'groups' && !$chat->isGroup())))

                        <div
                            wire:key="chat-item-{{ $chat->id }}"
                            class="rt-chat-list-entry group relative"
                        >
                            <x-chat.stacked-list-item
                                wire:click="openChat({{ $chat->id }})"
                                x-on:click="showChat()"
                                data-chat-list-item
                                data-chat-swipe-id="{{ $chat->id }}"
                                aria-current="{{ $isActive ? 'page' : 'false' }}"
                                :active="$isActive"
                                :unread="$unread > 0"
                                :show-chevron="false"
                                class="rt-chat-list-item pr-14"
                            >
                                <x-slot:avatar>
                                    <x-chat.avatar
                                        :src="$avatarUrl"
                                        :size="$embedded ? 'sm' : 'md'"
                                        :name="$chat->displayNameFor($me)"
                                        :signal="$previewPersonIsOnline"
                                        decorative
                                    />
                                </x-slot:avatar>

                                <x-slot:title>{{ $chat->displayNameFor($me) }}</x-slot:title>
                                <x-slot:context>
                                    {{ $chat->isGroup() ? __('app.group_chat') : __('app.direct_chat') }}
                                </x-slot:context>
                                <x-slot:meta>
                                    <i class="far fa-comment-dots" aria-hidden="true"></i>
                                    <span>
                                        @if ($latest)
                                            {{ (int) $latest->user_id === (int) $me->id ? __('app.you') . ': ' : '' }}{{ $latest->replyPreviewText() }}
                                        @else
                                            {{ __('app.no_messages_yet') }}
                                        @endif
                                    </span>
                                </x-slot:meta>

                                @if ($timeLabel !== '')
                                    <x-slot:time>{{ $timeLabel }}</x-slot:time>
                                @endif
                                @if ($unread > 0)
                                    <x-slot:badge>{{ $unread > 99 ? '99+' : $unread }}</x-slot:badge>
                                @endif
                            </x-chat.stacked-list-item>

                            <div class="rt-chat-list-options absolute right-1.5 top-1/2 z-[2] -translate-y-1/2">
                                <x-ui.dropdown.anchor-dropdown
                                    align="right"
                                    width="56"
                                    :offset="6"
                                    dropdown-id="chat-{{ $chat->id }}-options"
                                    layer-group="chat-actions"
                                    :content-label="__('app.chat_options') . ': ' . $chat->displayNameFor($me)"
                                >
                                    <x-slot name="trigger">
                                        <x-ui.dropdown.action-trigger
                                            orientation="vertical"
                                            :aria-label="__('app.chat_options') . ': ' . $chat->displayNameFor($me)"
                                            class="rt-chat-list-options-trigger h-11 w-11 rounded-xl border-0 bg-transparent px-0 shadow-none"
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
            {{-- chat-section:chat-list:end --}}
            {{-- chat-section:conversation:start --}}
            <div class="rt-chat-conversation-pane flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden" data-anim="fade" @if($embedded) x-bind:inert="!splitChat && mobilePane !== 'chat'" @endif>
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
                            microphoneBlockedText: @js(__('app.voice_microphone_blocked')),
                            uploadErrorText: @js(__('app.voice_upload_failed'))
                        })"
                    >
                        {{-- chat-section:conversation-header:start --}}
                        @php
                            $headerAvatar = $selectedChat->avatarUrlFor($me);
                            $headerPerson = $selectedChat->isGroup()
                                ? null
                                : $selectedChat->participants->firstWhere('id', '!=', $me->id);
                            $headerIsOnline = $headerPerson?->isOnline() ?? false;
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

                            <x-ui.buttons.button-basic
                                mode="icon"
                                tone="plain"
                                icon="far fa-arrow-left"
                                :label="__('app.back')"
                                data-chat-mobile-back
                                x-on:click="showList()"
                                class="rt-chat-icon-button rt-chat-icon-button--quiet rounded-xl text-sm rt-chat-mobile-back"
                            />

                            @if ($headerPerson)
                                <div class="rt-chat-header-identity flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
                                    <x-chat.avatar
                                        :src="$headerAvatar"
                                        :name="$selectedChat->displayNameFor($me)"
                                        size="lg"
                                        :signal="$headerIsOnline"
                                    />

                                    <span class="min-w-0 flex-1 [&>[data-person-anchor-preview]]:max-w-full">
                                        @php
                                            $headerCanReach = $headerPerson->isActive() && ! $headerPerson->is($me);
                                            $headerCanCall = $headerCanReach
                                                && ($me->isAdmin() || $me->hasRbacPermission('calls.start'))
                                                && ($headerPerson->isAdmin() || $headerPerson->hasRbacPermission('calls.join'));
                                        @endphp

                                        <x-user.person-anchor-preview
                                            :user="$headerPerson"
                                            trigger-classes="flex min-w-0 max-w-full"
                                            :profile-url="$me->canViewManagementDashboard()
                                                ? route($me->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $headerPerson->id)
                                                : null"
                                            :can-chat="false"
                                            :can-call="$headerCanCall"
                                            :can-message="$headerCanReach && $me->can('users.messages.create')"
                                            call-action="startDirectCall"
                                        >
                                            <x-slot:trigger>
                                                <button
                                                    type="button"
                                                    class="rt-chat-header-person-trigger group block min-w-0 max-w-full rounded-lg px-0.5 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                                                    title="{{ __('app.open_person_preview') }}"
                                                    aria-label="{{ __('app.open_person_preview') }}: {{ $headerPerson->name }}"
                                                    data-no-chat-swipe
                                                >
                                                    <span class="rt-chat-header-name block truncate text-sm font-extrabold tracking-[-0.025em] text-rt-text transition-colors group-hover:text-rt-red dark:text-rt-dark-text dark:group-hover:text-rt-dark-accent sm:text-[15px]">
                                                        {{ $selectedChat->displayNameFor($me) }}
                                                    </span>
                                                </button>
                                            </x-slot:trigger>
                                        </x-user.person-anchor-preview>
                                        <span class="rt-chat-header-status mt-0.5 block min-h-[1rem]" aria-live="polite" aria-atomic="true">
                                            <span
                                                x-show.important="!typingLabel"
                                                class="rt-chat-presence {{ $headerIsOnline ? 'is-online' : 'is-offline' }} inline-flex items-center gap-1.5 text-[10px] font-semibold"
                                                data-chat-presence="{{ $headerIsOnline ? 'online' : 'offline' }}"
                                            >
                                                <span class="rt-chat-presence__dot" aria-hidden="true"></span>
                                                {{ $headerIsOnline ? __('app.online') : __('app.offline') }}
                                            </span>
                                            <span
                                                x-cloak
                                                x-show.important="typingLabel"
                                                x-text="typingLabel"
                                                class="rt-chat-typing block truncate text-[10px] font-bold text-rt-accent dark:text-rt-dark-accent"
                                                data-chat-presence="typing"
                                            ></span>
                                        </span>
                                    </span>
                                </div>
                            @else
                                <x-chat.avatar
                                    :src="$headerAvatar"
                                    :name="$selectedChat->displayNameFor($me)"
                                    size="lg"
                                />

                                <div class="min-w-0 flex-1">
                                    <p class="rt-chat-header-name truncate text-sm font-extrabold tracking-[-0.025em] text-rt-text dark:text-rt-dark-text sm:text-[15px]">
                                        {{ $selectedChat->displayNameFor($me) }}
                                    </p>
                                    <div class="rt-chat-header-status mt-0.5 min-h-[1rem]" aria-live="polite" aria-atomic="true">
                                        <p x-show.important="!typingLabel" class="truncate text-[10px] font-medium uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                            {{ trans_choice('app.members_count', $selectedChat->participants->count()) }}
                                        </p>
                                        <p
                                            x-cloak
                                            x-show.important="typingLabel"
                                            x-text="typingLabel"
                                            class="rt-chat-typing truncate text-[10px] font-bold text-rt-accent dark:text-rt-dark-accent"
                                        ></p>
                                    </div>
                                </div>
                            @endif

                            @php $chatCallsReady = \Illuminate\Support\Facades\Schema::hasTable('rooms'); @endphp
                            @if ($chatCallsReady && $selectedChat->activeRoom)
                                @can('calls.join')
                                    <a
                                        href="{{ route('calls.window', $selectedChat->activeRoom) }}"
                                        class="rt-chat-header-call-link inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-500/10 px-3 text-xs font-bold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                                        title="{{ __('app.calls_join_ongoing') }}"
                                        aria-label="{{ __('app.calls_join_ongoing') }}"
                                        data-no-chat-swipe
                                    >
                                        <span class="relative flex h-2 w-2">
                                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-75"></span>
                                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                        </span>
                                        <span class="hidden md:inline">{{ __('app.calls_join_ongoing') }}</span>
                                        <i class="far fa-video md:hidden" aria-hidden="true"></i>
                                    </a>
                                @endcan
                            @elseif($chatCallsReady)
                                @can('calls.start')
                                    {{-- Sprachanruf: derselbe Raum, nur ohne Kamera beim Beitritt. --}}
                                    <x-ui.buttons.button-basic
                                        mode="icon"
                                        tone="plain"
                                        icon="far fa-phone"
                                        :label="__('app.calls_start_audio')"
                                        size="lg"
                                        wire:click="startCall(false)"
                                        wire:loading.attr="disabled"
                                        wire:target="startCall"
                                        data-no-chat-swipe
                                        class="rt-chat-icon-button rt-chat-icon-button--quiet rounded-xl text-sm rt-chat-header-action rounded-full"
                                    />

                                    <x-ui.buttons.button-basic
                                        mode="icon"
                                        tone="plain"
                                        icon="far fa-video"
                                        :label="__('app.calls_start')"
                                        size="lg"
                                        wire:click="startCall(true)"
                                        wire:loading.attr="disabled"
                                        wire:target="startCall"
                                        data-no-chat-swipe
                                        class="rt-chat-icon-button rt-chat-icon-button--quiet rounded-xl text-sm rt-chat-header-action rounded-full"
                                    />
                                @endcan
                            @endif

                            <x-ui.dropdown.anchor-dropdown
                                align="right"
                                width="56"
                                :offset="7"
                                dropdown-id="chat-conversation-{{ $selectedChat->id }}-actions"
                                layer-group="chat-actions"
                                :content-label="__('app.chat_options')"
                                class="rt-chat-options shrink-0"
                            >
                                <x-slot name="trigger">
                                    <x-ui.dropdown.action-trigger
                                        :aria-label="__('app.chat_options')"
                                        class="rt-chat-options-trigger h-11 w-11 rounded-full px-0"
                                        data-no-chat-swipe
                                    />
                                </x-slot>

                                <x-slot name="content">
                                    <div class="space-y-0.5 p-1.5" aria-label="{{ __('app.chat_options') }}">
                                        @if ($selectedChat->canManageGroup($me))
                                            <x-ui.dropdown.dropdown-link wire:click="openGroupSettings">
                                                <span class="rt-chat-option-icon">
                                                    <i class="far fa-users-cog" aria-hidden="true"></i>
                                                </span>
                                                <span>{{ __('app.group_settings') }}</span>
                                            </x-ui.dropdown.dropdown-link>
                                        @endif

                                        <x-ui.dropdown.dropdown-link
                                            as="a"
                                            :href="route('chat.export', ['chat' => $selectedChat])"
                                            data-no-chat-swipe
                                        >
                                            <span class="rt-chat-option-icon">
                                                <i class="far fa-file-export" aria-hidden="true"></i>
                                            </span>
                                            <span>{{ __('app.export_chat') }}</span>
                                        </x-ui.dropdown.dropdown-link>

                                        <div class="rt-chat-option-divider my-1" role="separator"></div>

                                        <x-ui.dropdown.dropdown-link
                                            wire:click="requestDeleteChat"
                                            data-rt-tone="danger"
                                        >
                                            <span class="rt-chat-option-icon">
                                                <i class="far {{ $selectedChat->isGroup() && ! $selectedChat->canManageGroup($me) ? 'fa-sign-out-alt' : 'fa-trash-alt' }}" aria-hidden="true"></i>
                                            </span>
                                            <span>
                                                @if ($selectedChat->isGroup() && ! $selectedChat->canManageGroup($me))
                                                    {{ __('app.leave_group') }}
                                                @elseif ($selectedChat->isGroup())
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
                        {{-- chat-section:conversation-header:end --}}
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
                                    @php
                                        $call = $message->callRoom ? app(\App\Services\Calls\CallTimelineService::class)->projectionFor($message, $me) : null;
                                        $callDirection = $call['direction'] ?? ((int) $message->user_id === (int) $me->id ? 'outgoing' : 'incoming');
                                        $callStatus = $call ? ([
                                            'ringing' => 'Klingelt', 'active' => 'Läuft', 'ended' => 'Beendet',
                                            'missed' => 'Verpasst', 'declined' => 'Abgelehnt', 'cancelled' => 'Abgebrochen',
                                            'not_connected' => 'Nicht verbunden', 'failed' => 'Verbindung fehlgeschlagen', 'removed' => 'Teilnahme beendet',
                                        ][$call['status']] ?? '') : 'Nicht mehr verfügbar';
                                        $duration = $call['duration_seconds'] ?? null;
                                        $previous = $index > 0 ? $items[$index - 1] : null;
                                    @endphp
                                    @if(!$previous || !$previous->created_at->isSameDay($message->created_at))
                                        <div class="flex justify-center py-3"><span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">{{ $message->created_at->isToday() ? __('app.today') : ($message->created_at->isYesterday() ? __('app.yesterday') : $message->created_at->format('d.m.Y')) }}</span></div>
                                    @endif
                                    <div wire:key="chat-call-{{ $message->id }}" data-chat-call-event="{{ $message->id }}" data-call-direction="{{ $callDirection }}" class="rt-chat-call-event flex items-center">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted">
                                            <i class="far {{ ($call['medium'] ?? 'audio') === 'audio' ? 'fa-phone' : 'fa-video' }}" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-bold">{{ ($call['medium'] ?? 'audio') === 'meeting' ? 'Meeting' : (($call['medium'] ?? 'audio') === 'video' ? 'Videoanruf' : 'Sprachanruf') }}</p>
                                            <p class="mt-1 text-[10px] text-rt-muted dark:text-rt-dark-muted">
                                                {{ $message->created_at->format('H:i') }} · {{ $callStatus }}
                                                @if($duration !== null) · {{ gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', (int)$duration) }} @endif
                                            </p>
                                        </div>
                                        @if($call && $call['can_join'])
                                            <a href="{{ route('calls.window', ['room' => $call['room_uuid']]) }}" class="shrink-0 text-xs font-semibold text-rt-red">Beitreten</a>
                                        @elseif($call && in_array($call['status'], ['ended','missed','declined','cancelled','not_connected','failed']) && ($me->isAdmin() || $me->hasRbacPermission('calls.start')))
                                            <button type="button" wire:click="startCall({{ $call['medium'] !== 'audio' ? 'true' : 'false' }})" wire:loading.attr="disabled" data-no-chat-swipe class="shrink-0 text-xs font-semibold text-rt-red">Rückruf</button>
                                        @endif
                                        @if($call && $call['can_open_history'])
                                            <a href="{{ route('calls.history', ['room' => $call['room_uuid']]) }}" class="rt-chat-icon-button shrink-0" aria-label="Anrufdetails" title="Anrufdetails"><i class="far fa-info-circle" aria-hidden="true"></i></a>
                                        @endif
                                    </div>
                                @else
                                @php
                                    $prev = $index > 0 ? $items[$index - 1] : null;
                                    $own = (int) $message->user_id === (int) $me->id;
                                    $deleted = $message->trashed();
                                    $newDay = ! $prev || ! $prev->created_at->isSameDay($message->created_at);
                                    $chip = $message->created_at->isToday()
                                        ? __('app.today')
                                        : ($message->created_at->isYesterday() ? __('app.yesterday') : $message->created_at->format('d.m.Y'));
                                    $showSender = $selectedChat->isGroup() && ! $own;
                                    $isRead = $own && ! $deleted && $selectedChat->messageReadByAllRecipients($message, $me);
                                    $isLiveLocation = ! $deleted && $message->isLiveLocation();
                                    $voiceFile = $deleted ? null : $message->voiceFile();
                                    $voiceConsumed = $voiceFile && $message->view_once
                                        && ($own || ($message->hasBeenViewedBy($me) && ! $message->hasActiveVoicePlaybackFor($me)));
                                    $messageSurface = $deleted
                                        ? 'rt-chat-message--deleted'
                                        : ($isLiveLocation
                                            ? 'rt-chat-message--live-location'
                                            : ($voiceFile
                                            ? 'rt-chat-message--voice'
                                            : ($message->files->isNotEmpty() ? 'rt-chat-message--attachment' : 'rt-chat-message--text')));
                                    $reactionGroups = $message->reactions->groupBy('emoji');
                                    $myReaction = $message->reactions->firstWhere('user_id', $me->id)?->emoji;
                                @endphp

                                <div
                                    wire:key="chat-msg-{{ $message->id }}"
                                    data-chat-message-row
                                    data-chat-message-id="{{ $message->id }}"
                                    class="rt-chat-message-row flex w-full flex-col"
                                >
                                    @if ($newDay)
                                        <div class="flex justify-center py-3">
                                            <span class="rt-chat-date-chip rounded-full px-3 py-1 text-[9px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">
                                                {{ $chip }}
                                            </span>
                                        </div>
                                    @endif

                                    <div class="rt-chat-message-line {{ $own ? 'rt-chat-message-line--own ml-auto flex-row-reverse' : 'mr-auto' }} {{ $selectedChat->isGroup() ? 'rt-chat-message-line--group' : 'rt-chat-message-line--direct' }} flex items-end gap-2">
                                        @if ($selectedChat->isGroup())
                                            <x-chat.avatar
                                                :src="$message->sender?->profile_photo_url"
                                                :name="$message->sender?->name ?? __('app.unknown')"
                                                size="xs"
                                                class="rt-chat-message-avatar mb-0.5"
                                            />
                                        @endif

                                        <div
                                            x-data="chatMessageActions({ messageId: {{ $message->id }}, controllerId: 'chat-{{ $message->id }}', disabled: @js($deleted), canReact: @js(! $own && ! $deleted) })"
                                            data-chat-message-controller="chat-{{ $message->id }}"
                                            class="rt-chat-message-stack {{ $reactionGroups->isNotEmpty() ? 'rt-chat-message-stack--reacted' : '' }} {{ $own ? 'items-end' : 'items-start' }} relative flex min-w-0 max-w-full flex-col"
                                        >
                                            <div class="rt-chat-message-bubble-wrap relative max-w-full">
                                                <div
                                                    data-rt-chat-message="{{ $own ? 'own' : 'other' }}"
                                                    tabindex="{{ $deleted ? '-1' : '0' }}"
                                                    x-ref="messageBubble"
                                                    x-on:contextmenu.stop="openActionsAtPointer($event)"
                                                    x-on:pointerdown="startLongPress($event)"
                                                    x-on:pointermove="moveLongPress($event)"
                                                    x-on:pointerup="finishLongPress($event)"
                                                    x-on:pointercancel="cancelLongPress()"
                                                    x-on:keydown="handleKeyboard($event)"
                                                    x-on:click="handleClick($event)"
                                                    class="rt-chat-message {{ $own
                                                        ? 'rt-chat-message--own rt-chat-message--actionable rounded-br-md'
                                                        : 'rt-chat-message--other rt-chat-message--actionable rounded-bl-md' }} {{ $messageSurface }} relative rounded-[1.15rem] px-3.5 py-2.5 text-[13px] leading-5 sm:px-4"
                                                >
                                                @unless ($deleted)
                                                    <x-chat.message-dropdown
                                                        :message-id="$message->id"
                                                        controller-id="chat-{{ $message->id }}"
                                                        :own="$own"
                                                        :quick-reactions="$quickReactions"
                                                        :allowed-reactions="$allowedReactions"
                                                        :my-reaction="$myReaction"
                                                        delete-method="requestDeleteMessage"
                                                        trigger-variant="bubble"
                                                        dropdown-id="chat-message-{{ $message->id }}-actions"
                                                    />
                                                @endunless

                                                @if ($showSender)
                                                    <p class="rt-chat-message-sender mb-1 text-[10px] font-extrabold tracking-[0.01em]">
                                                        {{ $message->sender?->name }}
                                                    </p>
                                                @endif

                                                @if ($message->reply_to_message_id)
                                                    @php
                                                        $reply = $message->replyTo;
                                                    @endphp
                                                    @if ($reply)
                                                        <button
                                                            type="button"
                                                            x-on:click.stop="$dispatch('chat:scroll-to-message', { messageId: {{ $reply->id }} })"
                                                            data-no-chat-swipe
                                                            class="rt-chat-reply-quote mb-2 block w-full rounded-xl px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                                        >
                                                            <span class="block truncate text-[10px] font-extrabold">{{ $reply->sender?->name ?? __('app.unknown') }}</span>
                                                            <span class="block truncate text-[11px] opacity-80">{{ $reply->replyPreviewText() }}</span>
                                                        </button>
                                                    @else
                                                        <div class="rt-chat-reply-quote mb-2 rounded-xl px-3 py-2 text-[11px] italic opacity-80">
                                                            {{ __('app.chat_original_message_unavailable') }}
                                                        </div>
                                                    @endif
                                                @endif

                                                @if ($deleted)
                                                    <p class="rt-chat-deleted-copy flex items-center gap-2 italic">
                                                        <i class="far fa-ban" aria-hidden="true"></i>
                                                        <span>{{ __('app.chat_message_deleted') }}</span>
                                                    </p>
                                                @else
                                                    @if ($isLiveLocation)
                                                        <x-chat.live-location-card :message="$message" :own="$own" />
                                                    @else
                                                        @if (filled($message->body))
                                                            <p class="rt-chat-message-copy whitespace-pre-wrap break-words">{{ $message->body }}</p>
                                                        @endif

                                                        @if ($voiceFile)
                                                            <x-chat.voice-message :message="$message" :file="$voiceFile" :own="$own" :consumed="$voiceConsumed" />
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
                                                                    x-on:click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                                                                    data-no-chat-swipe
                                                                    class="{{ $isImage || $isVideo ? 'rt-chat-image-preview group/image relative block overflow-hidden rounded-2xl bg-black/10' : 'rt-chat-file-card flex items-center gap-3 rounded-xl px-2.5 py-2' }} min-w-0 max-w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/50"
                                                                    title="{{ __('app.preview') }}"
                                                                    aria-label="{{ __('app.preview') }}: {{ $file->name }}"
                                                                >
                                                                    @if ($isImage)
                                                                        <img src="{{ $inlineUrl }}" alt="{{ $file->name }}" loading="lazy" class="max-h-80 w-auto max-w-full object-contain transition duration-500 ease-rt-spring group-hover/image:scale-[1.015]">
                                                                    @elseif ($isVideo)
                                                                        <span class="relative flex min-h-36 w-full max-w-[21rem] items-center justify-center bg-black/85 text-white">
                                                                            <video src="{{ $inlineUrl }}" preload="metadata" muted playsinline aria-hidden="true" class="absolute inset-0 h-full w-full object-cover opacity-75"></video>
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
                                                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/15 backdrop-blur-md"><i class="far fa-expand" aria-hidden="true"></i></span>
                                                                        </span>
                                                                    @else
                                                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg opacity-70"><i class="far fa-eye" aria-hidden="true"></i></span>
                                                                    @endif
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                        @endif
                                                    @endif
                                                @endif

                                                </div>

                                                @if (! $deleted && $reactionGroups->isNotEmpty())
                                                    <div class="rt-chat-reactions rt-chat-reactions--overlay {{ $own ? 'rt-chat-reactions--own' : 'rt-chat-reactions--other' }} flex max-w-full flex-nowrap items-center gap-1 px-1" data-chat-action-ignore data-no-chat-swipe aria-label="{{ __('app.chat_message_reactions') }}">
                                                        @foreach ($allowedReactions as $emoji)
                                                            @if ($reactionGroups->has($emoji))
                                                                @php
                                                                    $count = $reactionGroups->get($emoji)->count();
                                                                @endphp
                                                                @if ($own)
                                                                    <span
                                                                        class="rt-chat-reaction-chip inline-flex items-center justify-center gap-1 px-0.5 text-sm"
                                                                        aria-label="{{ trans_choice('app.chat_reaction_count', $count, ['emoji' => $emoji, 'count' => $count]) }}"
                                                                    >
                                                                        <span aria-hidden="true">{{ $emoji }}</span>
                                                                        @if ($count > 1)<span class="text-[10px] font-extrabold tabular-nums">{{ $count }}</span>@endif
                                                                    </span>
                                                                @else
                                                                    <x-chat.reaction-dropdown
                                                                        :message-id="$message->id"
                                                                        :emoji="$emoji"
                                                                        :count="$count"
                                                                        :names="$reactionGroups->get($emoji)->pluck('user.name')->filter()->join(', ')"
                                                                        :my-reaction="$myReaction"
                                                                        dropdown-id="chat-reaction-{{ $message->id }}-{{ md5($emoji) }}"
                                                                    />
                                                                @endif
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="rt-chat-message-meta rt-chat-message-meta--{{ $own ? 'own' : 'other' }} flex items-center gap-1 text-[9px] font-semibold leading-none">
                                                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('H:i') }}</time>
                                                @if ($own && ! $deleted)
                                                    <i class="rt-chat-read-indicator far fa-check-double {{ $isRead ? 'is-read' : 'is-delivered' }}" title="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}" aria-label="{{ $isRead ? __('app.message_read') : __('app.message_delivered') }}"></i>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
                        {{-- chat-section:composer:start --}}
                        <div
                            class="rt-chat-composer shrink-0 px-2 pb-2.5 pt-2 sm:px-3 sm:pb-3"
                            data-no-chat-swipe
                            wire:key="chat-composer-{{ $selectedChat->id }}"
                        >
                            @if ($replyingToMessage)
                                <div class="rt-chat-composer-reply mb-2 flex items-stretch gap-1 rounded-xl p-1" aria-live="polite">
                                    <button
                                        type="button"
                                        x-on:click="$dispatch('chat:scroll-to-message', { messageId: {{ $replyingToMessage->id }} })"
                                        class="min-w-0 flex-1 rounded-lg px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/50"
                                    >
                                        <span class="block truncate text-[10px] font-extrabold text-rt-accent dark:text-rt-dark-accent">
                                            {{ __('app.chat_replying_to', ['name' => $replyingToMessage->sender?->name ?? __('app.unknown')]) }}
                                        </span>
                                        <span class="block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                            {{ $replyingToMessage->replyPreviewText() }}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="cancelReply"
                                        class="rt-chat-upload-remove inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg"
                                        title="{{ __('app.chat_cancel_reply') }}"
                                        aria-label="{{ __('app.chat_cancel_reply') }}"
                                    >
                                        <i class="far fa-times" aria-hidden="true"></i>
                                    </button>
                                </div>
                            @endif

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
                                x-on:chat:reply-started.window="$nextTick(() => $refs.messageInput?.focus())"
                                class="flex items-center"
                            >
                                <input
                                    id="chat-attachments-{{ $selectedChat->id }}"
                                    x-ref="attachmentInput"
                                    type="file"
                                    wire:model="uploads"
                                    multiple
                                    accept="audio/*,video/*,image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                                    tabindex="-1"
                                    class="sr-only"
                                >

                                <x-ui.floating.composer-row
                                    x-show.important="!recording && !sendingVoice"
                                    data-chat-composer-mode="text"
                                    class="rt-chat-composer-row"
                                    control-class="rt-chat-composer-control"
                                >
                                    <x-slot:leading>
                                    <button
                                        type="button"
                                        @click="$refs.attachmentInput.click()"
                                        title="{{ __('app.add_attachment') }}"
                                        aria-label="{{ __('app.add_attachment') }}"
                                        class="rt-floating-composer-attachment rt-chat-composer-action rt-chat-composer-action--detached flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full"
                                        data-chat-composer-attachment
                                    >
                                        <i class="far fa-plus" aria-hidden="true"></i>
                                    </button>
                                    </x-slot:leading>
                                        <x-chat.live-location-share
                                            :chat-id="$selectedChat->id"
                                            :start-url="route('chat.live-locations.store', ['chat' => $selectedChat])"
                                            :reply-to-message-id="$replyingToMessage?->id"
                                            context="chat"
                                        />

                                        <input
                                            x-ref="messageInput"
                                            type="text"
                                            x-model="draft"
                                            @input.debounce.250ms="sendTyping()"
                                            placeholder="{{ __('app.type_message') }}"
                                            aria-label="{{ __('app.type_message') }}"
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
                                            class="rt-chat-composer-action flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                                        >
                                            <i class="far fa-microphone" aria-hidden="true"></i>
                                            <span class="sr-only">{{ __('app.voice_message') }}</span>
                                        </button>
                                    <x-slot:actions>
                                    <button
                                        x-cloak
                                        x-show.important="draft.trim().length > 0 || @js($uploads !== [])"
                                        x-transition:enter="transition duration-150 ease-out"
                                        x-transition:enter-start="scale-75 opacity-0"
                                        x-transition:enter-end="scale-100 opacity-100"
                                        x-transition:leave="transition duration-100 ease-in"
                                        x-transition:leave-start="scale-100 opacity-100"
                                        x-transition:leave-end="scale-75 opacity-0"
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="send"
                                        title="{{ __('app.send_message') }}"
                                        aria-label="{{ __('app.send_message') }}"
                                        class="rt-floating-composer-send rt-chat-send-button flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-white disabled:cursor-wait disabled:opacity-70"
                                        data-chat-send-button
                                    >
                                        <i wire:loading.remove wire:target="send" class="far fa-paper-plane" aria-hidden="true"></i>
                                        <i wire:loading wire:target="send" class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                    </button>
                                    </x-slot:actions>
                                </x-ui.floating.composer-row>

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
                        {{-- chat-section:composer:end --}}
                    </div>
                @endif
            </div>
            {{-- chat-section:conversation:end --}}
        </div>
    </div>

    {{-- chat-section:new-chat-modal:start --}}
    <div class="rt-chat-new-modal">
        <x-dialog-modal wire:model="showNewChat" maxWidth="md">
            <x-slot name="title">
                <span class="rt-chat-modal-title flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-accent dark:text-rt-dark-accent">
                        <i class="fad fa-comment-plus" aria-hidden="true"></i>
                    </span>
                    <span>
                        <span class="block text-[10px] font-bold uppercase tracking-[0.13em] text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.chat_and_messages') }}
                        </span>
                        <span class="mt-0.5 block text-lg font-extrabold tracking-[-0.035em]">
                            {{ $newChatTab === 'group' ? __('app.new_group') : __('app.new_chat') }}
                        </span>
                    </span>
                </span>
            </x-slot>

            <x-slot name="content">
                <div
                    class="rt-chat-modal-tabs mb-5 grid grid-cols-2 gap-1 rounded-xl p-1"
                    role="tablist"
                    aria-label="{{ __('app.chat_and_messages') }}"
                >
                    <button
                        id="rt-new-chat-tab-direct"
                        type="button"
                        role="tab"
                        aria-selected="{{ $newChatTab === 'direct' ? 'true' : 'false' }}"
                        aria-controls="rt-new-chat-panel-direct"
                        tabindex="{{ $newChatTab === 'direct' ? '0' : '-1' }}"
                        wire:click="$set('newChatTab', 'direct')"
                        class="rt-chat-modal-tab {{ $newChatTab === 'direct' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                    >
                        <i class="far fa-user mr-1.5" aria-hidden="true"></i>
                        {{ __('app.new_chat') }}
                    </button>
                    <button
                        id="rt-new-chat-tab-group"
                        type="button"
                        role="tab"
                        aria-selected="{{ $newChatTab === 'group' ? 'true' : 'false' }}"
                        aria-controls="rt-new-chat-panel-group"
                        tabindex="{{ $newChatTab === 'group' ? '0' : '-1' }}"
                        wire:click="$set('newChatTab', 'group')"
                        class="rt-chat-modal-tab {{ $newChatTab === 'group' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                    >
                        <i class="far fa-users mr-1.5" aria-hidden="true"></i>
                        {{ __('app.new_group') }}
                    </button>
                </div>

                @if ($showNewChat)
                    @if ($newChatTab === 'direct')
                        <div
                            id="rt-new-chat-panel-direct"
                            role="tabpanel"
                            aria-labelledby="rt-new-chat-tab-direct"
                        >
                            @include('livewire.chat.partials.member-picker', [
                                'pickerPaginator' => $contacts,
                                'pickerMode' => 'direct',
                                'searchId' => 'direct-contact-search',
                                'searchModel' => 'directContactSearch',
                                'pageName' => 'directContactsPage',
                                'rowPrefix' => 'direct-contact',
                            ])
                        </div>
                    @else
                        <div
                            id="rt-new-chat-panel-group"
                            class="space-y-4"
                            role="tabpanel"
                            aria-labelledby="rt-new-chat-tab-group"
                        >
                            <div>
                                <x-ui.forms.label for="group-name" :value="__('app.group_name')" />
                                <x-ui.forms.input
                                    type="text"
                                    id="group-name"
                                    wire:model="groupName"
                                    autocomplete="off"
                                    class="mt-1.5 rounded-xl"
                                />
                                @error('groupName')
                                    <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <x-ui.forms.label :value="__('app.select_participants')" />
                                <div class="mt-1.5">
                                    @include('livewire.chat.partials.member-picker', [
                                        'pickerPaginator' => $groupParticipantsPaginator,
                                        'pickerMode' => 'select',
                                        'selectionModel' => 'groupParticipants',
                                        'selectedIds' => $groupParticipants,
                                        'searchId' => 'group-participant-search',
                                        'searchModel' => 'groupParticipantSearch',
                                        'pageName' => 'groupParticipantsPage',
                                        'rowPrefix' => 'group-participant',
                                    ])
                                </div>
                                @error('groupParticipants')
                                    <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endif
                @endif
            </x-slot>

            <x-slot name="footer">
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="$toggle('showNewChat')">
                        {{ __('app.cancel') }}
                    </x-ui.buttons.button-basic>
                    @if ($newChatTab === 'group')
                        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="createGroup">
                            {{ __('app.save') }}
                        </x-ui.buttons.button-basic>
                    @endif
                </div>
            </x-slot>
        </x-dialog-modal>
    </div>
    {{-- chat-section:new-chat-modal:end --}}
    {{-- chat-section:chat-options-modals:start --}}
    <x-confirmation-modal id="rt-delete-chat-message-modal" wire:model="showDeleteMessageModal" maxWidth="sm">
        <x-slot name="title">
            {{ __('app.delete_message_title') }}
        </x-slot>

        <x-slot name="content">
            {{ __('app.delete_message_hint') }}
        </x-slot>

        <x-slot name="footer">
            <x-ui.buttons.button-basic
                type="button"
                mode="secondary"
                wire:click="$set('showDeleteMessageModal', false)"
            >
                {{ __('app.cancel') }}
            </x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic
                type="button"
                mode="danger"
                wire:click="confirmDeleteMessage"
                wire:loading.attr="disabled"
                wire:target="confirmDeleteMessage"
            >
                {{ __('app.delete') }}
            </x-ui.buttons.button-basic>
        </x-slot>
    </x-confirmation-modal>

    <x-confirmation-modal id="rt-delete-chat-modal" wire:model="showDeleteChatModal" maxWidth="sm">
        @php
            $deleteTargetChat = $pendingDeleteChat ?? $selectedChat;
        @endphp

        <x-slot name="title">
            @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
                {{ __('app.leave_group') }}
            @elseif ($deleteTargetChat?->isGroup())
                {{ __('app.delete_group') }}
            @else
                {{ __('app.delete_chat') }}
            @endif
        </x-slot>

        <x-slot name="content">
            @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
                {{ __('app.leave_group_hint') }}
            @elseif ($deleteTargetChat?->isGroup())
                {{ __('app.delete_group_hint') }}
            @else
                {{ __('app.delete_direct_chat_hint') }}
            @endif
        </x-slot>

        <x-slot name="footer">
            <x-ui.buttons.button-basic
                type="button"
                mode="secondary"
                wire:click="$set('showDeleteChatModal', false)"
            >
                {{ __('app.cancel') }}
            </x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic
                type="button"
                mode="danger"
                wire:click="confirmDeleteChat"
                wire:loading.attr="disabled"
                wire:target="confirmDeleteChat"
            >
                @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
                    {{ __('app.leave_group') }}
                @else
                    {{ __('app.delete') }}
                @endif
            </x-ui.buttons.button-basic>
        </x-slot>
    </x-confirmation-modal>

    <div class="rt-chat-group-modal">
        <x-dialog-modal id="rt-group-settings-modal" wire:model="showGroupSettings" maxWidth="lg">
            <x-slot name="title">
                <span class="rt-chat-modal-title flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-accent dark:text-rt-dark-accent">
                        <i class="fad fa-users-cog" aria-hidden="true"></i>
                    </span>
                    <span>
                        <span class="block text-[10px] font-bold uppercase tracking-[0.13em] text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.manage_group') }}
                        </span>
                        <span class="mt-0.5 block text-lg font-extrabold tracking-[-0.035em]">
                            {{ __('app.group_settings') }}
                        </span>
                    </span>
                </span>
            </x-slot>

            <x-slot name="content">
                @if ($showGroupSettings && $groupCandidates)
                    <div class="space-y-5">
                        {{-- Gruppenbild: Vorschau (neues Bild > aktuelles Bild > Initiale)
                             plus Dateiauswahl. Gespeichert wird erst mit "Speichern". --}}
                        <div class="flex items-center gap-4">
                            @php
                                $currentGroupPhoto = $selectedChat?->avatarUrlFor($me);
                            @endphp
                            <span class="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-rt-surface-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                                @if ($groupPhotoUpload)
                                    <img src="{{ $groupPhotoUpload->temporaryUrl() }}" alt="" class="h-full w-full object-cover" />
                                @elseif ($currentGroupPhoto)
                                    <img src="{{ $currentGroupPhoto }}" alt="" class="h-full w-full object-cover" />
                                @else
                                    <span class="text-xl font-extrabold text-rt-muted dark:text-rt-dark-muted">
                                        {{ mb_substr(trim($groupEditName) !== '' ? $groupEditName : 'G', 0, 1) }}
                                    </span>
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <x-ui.forms.label for="group-photo-upload" :value="__('app.group_photo')" />
                                <label
                                    for="group-photo-upload"
                                    class="mt-1.5 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-rt-border bg-rt-control px-3 py-1.5 text-xs font-semibold text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:bg-rt-dark-surface-muted"
                                >
                                    <i class="fad fa-camera" aria-hidden="true"></i>
                                    {{ __('app.group_photo_choose') }}
                                </label>
                                <input
                                    type="file"
                                    id="group-photo-upload"
                                    accept="image/*"
                                    wire:model="groupPhotoUpload"
                                    class="sr-only"
                                />
                                <p class="mt-1 text-[10px] leading-4 text-rt-muted dark:text-rt-dark-muted" wire:loading.remove wire:target="groupPhotoUpload">
                                    {{ __('app.group_photo_hint') }}
                                </p>
                                <p class="mt-1 text-[10px] font-semibold text-rt-accent dark:text-rt-dark-accent" wire:loading wire:target="groupPhotoUpload">
                                    {{ __('app.uploading') }}…
                                </p>
                                @error('groupPhotoUpload')
                                    <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <x-ui.forms.label for="group-edit-name" :value="__('app.group_name')" />
                            <x-ui.forms.input
                                type="text"
                                id="group-edit-name"
                                wire:model="groupEditName"
                                autocomplete="off"
                                class="mt-1.5"
                            />
                            @error('groupEditName')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <div class="flex items-end justify-between gap-3">
                                <x-ui.forms.label :value="__('app.group_members')" />
                                <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.invite_or_remove_members') }}
                                </span>
                            </div>

                            <div class="rt-chat-group-owner mt-2 flex min-h-14 min-w-0 items-center gap-2 rounded-xl px-2.5 py-2">
                                <x-user.public-info
                                    :user="$me"
                                    :size="9"
                                    :show-email="true"
                                    :show-presence="false"
                                    class="min-w-0 flex-1"
                                />
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-rt-muted dark:text-rt-dark-muted"
                                    title="{{ __('app.group_owner') }}"
                                    aria-label="{{ __('app.group_owner') }}"
                                >
                                    <i class="far fa-lock text-xs" aria-hidden="true"></i>
                                </span>
                                <span class="sr-only">
                                    {{ __('app.group_owner') }}
                                </span>
                            </div>

                            <div class="mt-2">
                                @include('livewire.chat.partials.member-picker', [
                                    'pickerPaginator' => $groupCandidates,
                                    'pickerMode' => 'select',
                                    'selectionModel' => 'groupMemberIds',
                                    'selectedIds' => $groupMemberIds,
                                    'searchId' => 'group-member-search',
                                    'searchModel' => 'groupMemberSearch',
                                    'pageName' => 'groupMembersPage',
                                    'rowPrefix' => 'group-settings-member',
                                ])
                            </div>
                            @error('groupMemberIds')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                            @error('groupMemberIds.*')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            </x-slot>

            <x-slot name="footer">
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.buttons.button-basic
                        type="button"
                        mode="secondary"
                        wire:click="$set('showGroupSettings', false)"
                    >
                        {{ __('app.cancel') }}
                    </x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic
                        type="button"
                        mode="primary"
                        wire:click="saveGroupSettings"
                        wire:loading.attr="disabled"
                        wire:target="saveGroupSettings"
                    >
                        {{ __('app.save_group_changes') }}
                    </x-ui.buttons.button-basic>
                </div>
            </x-slot>
        </x-dialog-modal>
    </div>
    {{-- chat-section:chat-options-modals:end --}}
    <livewire:people.person-preview-modal :key="'chat-person-preview-modal'" />

    {{-- Traegt die Schaltflaeche "Nachricht senden" aus den Personenkarten. --}}
    @can('users.messages.create')
        <livewire:admin.users.messages.message-form :key="'chat-message-form'" />
    @endcan
</div>
