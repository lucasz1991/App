{{--
    Chat: Gespraechsliste (linke Spalte) mit Suche, Filter und Zeilenmenue.
    Kein eigener Alpine-Scope: nutzt die Pane-Steuerung des Wurzelelements.
    Erwartet: $chats, $selectedChatId, $listFilter, $me.
--}}
{{-- chat-section:chat-list:start --}}
<div
    id="rt-chat-overview"
    class="rt-chat-list-pane flex min-h-0 w-full shrink-0 flex-col overflow-hidden md:w-[21.5rem]"
    data-anim="left"
    x-bind:inert="!splitChat && mobilePane !== 'list'"
>
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
            @continue(($listFilter === 'unread' && $unread === 0) || ($listFilter === 'groups' && !$chat->isGroup()))

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
                            size="sm"
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
                                    data-no-navigate
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
                                    tone="danger"
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
