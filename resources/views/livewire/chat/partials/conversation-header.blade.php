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

    <x-chat.icon-button
        icon="far fa-arrow-left"
        :label="__('app.back')"
        class="rt-chat-mobile-back"
        data-chat-mobile-back
        x-on:click="showList()"
    />

    @if ($headerPerson)
        <div class="flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
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
                            class="group block min-w-0 max-w-full rounded-lg px-0.5 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                            title="{{ __('app.open_person_preview') }}"
                            aria-label="{{ __('app.open_person_preview') }}: {{ $headerPerson->name }}"
                            data-no-chat-swipe
                        >
                            <span class="block truncate text-sm font-extrabold tracking-[-0.025em] text-rt-text transition-colors group-hover:text-rt-red dark:text-rt-dark-text dark:group-hover:text-rt-dark-accent sm:text-[15px]">
                                {{ $selectedChat->displayNameFor($me) }}
                            </span>
                        </button>
                    </x-slot:trigger>
                </x-user.person-anchor-preview>
                <span class="mt-0.5 block min-h-[1rem]" aria-live="polite" aria-atomic="true">
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
            <p class="truncate text-sm font-extrabold tracking-[-0.025em] text-rt-text dark:text-rt-dark-text sm:text-[15px]">
                {{ $selectedChat->displayNameFor($me) }}
            </p>
            <div class="mt-0.5 min-h-[1rem]" aria-live="polite" aria-atomic="true">
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

    @if ($selectedChat->activeRoom)
        @can('calls.join')
            <a
                href="{{ route('calls.window', $selectedChat->activeRoom) }}"
                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-500/10 px-3 text-xs font-bold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                title="{{ __('app.calls_join_ongoing') }}"
                aria-label="{{ __('app.calls_join_ongoing') }}"
                data-no-chat-swipe
            >
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                </span>
                <span class="hidden sm:inline">{{ __('app.calls_join_ongoing') }}</span>
                <i class="far fa-video sm:hidden" aria-hidden="true"></i>
            </a>
        @endcan
    @else
        @can('calls.start')
            {{-- Sprachanruf: derselbe Raum, nur ohne Kamera beim Beitritt. --}}
            <x-chat.icon-button
                icon="far fa-phone"
                :label="__('app.calls_start_audio')"
                tone="quiet"
                size="lg"
                class="rt-chat-header-action rounded-full"
                wire:click="startCall(false)"
                wire:loading.attr="disabled"
                wire:target="startCall"
                data-no-chat-swipe
            />

            <x-chat.icon-button
                icon="far fa-video"
                :label="__('app.calls_start')"
                tone="quiet"
                size="lg"
                class="rt-chat-header-action rounded-full"
                wire:click="startCall(true)"
                wire:loading.attr="disabled"
                wire:target="startCall"
                data-no-chat-swipe
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
