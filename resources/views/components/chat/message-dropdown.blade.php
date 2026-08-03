@props([
    'messageId',
    'controllerId' => null,
    'own' => false,
    'reactMethod' => 'setReaction',
    'replyMethod' => 'beginReply',
    'deleteMethod' => null,
    'deleteLabel' => null,
    'quickReactions' => [],
    'allowedReactions' => [],
    'myReaction' => null,
    'triggerVariant' => 'bubble',
    'dropdownId' => null,
])

@php
    $quick = array_values($quickReactions);
    $extended = array_values(array_filter(
        $allowedReactions,
        fn (string $emoji): bool => ! in_array($emoji, $quick, true),
    ));
    $resolvedId = $dropdownId ?: 'chat-message-'.$messageId.'-actions';
    $resolvedControllerId = $controllerId ?: (string) $messageId;
    $resolvedDeleteLabel = $deleteLabel ?: __('app.delete_chat_message');
    $bubbleTrigger = $triggerVariant === 'bubble';
@endphp

<x-ui.dropdown.anchor-dropdown
    align="right"
    width="auto"
    :offset="6"
    :dropdown-id="$resolvedId"
    :content-label="__('app.message_options')"
    :anchor-selector="$bubbleTrigger ? '[data-rt-chat-message]' : null"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    class="{{ $bubbleTrigger ? '!absolute left-0 top-0 h-0 w-0 overflow-visible pointer-events-none' : '' }}"
    data-chat-message-dropdown
    data-no-chat-swipe
>
    <x-slot name="trigger">
        <button
            type="button"
            x-ref="messageActionTrigger"
            data-chat-message-action-trigger="{{ $resolvedControllerId }}"
            x-on:click="if ($event.isTrusted) prepareActionMenu()"
            @class([
                'sr-only' => $bubbleTrigger,
                'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted opacity-100 transition hover:bg-rt-surface-muted hover:text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/50 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text sm:opacity-0 sm:group-hover:opacity-100' => ! $bubbleTrigger,
            ])
            @if ($bubbleTrigger) tabindex="-1" aria-hidden="true" @endif
            title="{{ __('app.message_options') }}"
            aria-label="{{ __('app.message_options') }}"
        >
            @unless ($bubbleTrigger)
                <i class="far fa-ellipsis" aria-hidden="true"></i>
            @endunless
        </button>
    </x-slot>

    <x-slot name="content">
        <div
            x-cloak
            x-show.important="menuMode === 'actions'"
            x-bind:style="menuMode === 'actions' ? '' : 'display: none !important;'"
            class="w-64 max-w-[calc(100vw-1.5rem)] space-y-0.5 p-1.5"
            data-chat-action-menu
        >
            @unless ($own)
                <x-ui.dropdown.dropdown-link
                    x-on:click="showReactionMenu()"
                    data-rt-dropdown-keep-open
                >
                    <span class="rt-chat-option-icon"><i class="far fa-face-smile" aria-hidden="true"></i></span>
                    <span>{{ __('app.chat_react') }}</span>
                    <i class="far fa-chevron-right ml-auto text-xs text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                </x-ui.dropdown.dropdown-link>
            @endunless

            <x-ui.dropdown.dropdown-link wire:click="{{ $replyMethod }}({{ (int) $messageId }})">
                <span class="rt-chat-option-icon"><i class="far fa-reply" aria-hidden="true"></i></span>
                <span>{{ __('app.chat_reply') }}</span>
            </x-ui.dropdown.dropdown-link>

            @if ($own && filled($deleteMethod))
                <div class="rt-chat-option-divider my-1" role="separator"></div>
                <x-ui.dropdown.dropdown-link
                    wire:click="{{ $deleteMethod }}({{ (int) $messageId }})"
                    data-rt-tone="danger"
                >
                    <span class="rt-chat-option-icon"><i class="far fa-trash-alt" aria-hidden="true"></i></span>
                    <span>{{ $resolvedDeleteLabel }}</span>
                </x-ui.dropdown.dropdown-link>
            @endif
        </div>

        @unless ($own)
            <div
                x-cloak
                x-show.important="menuMode === 'reactions'"
                x-bind:style="menuMode === 'reactions' ? '' : 'display: none !important;'"
                class="w-[min(22rem,calc(100vw-1.5rem))] p-1.5"
                data-chat-reaction-menu
                aria-label="{{ __('app.chat_quick_reactions') }}"
            >
                <div class="mb-1 flex min-h-10 items-center gap-2 px-1">
                    <button
                        type="button"
                        x-on:click="showActionMenu()"
                        data-rt-dropdown-keep-open
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/40 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text"
                        aria-label="{{ __('app.chat_back_to_actions') }}"
                    >
                        <i class="far fa-chevron-left" aria-hidden="true"></i>
                    </button>
                    <span class="text-xs font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.chat_choose_reaction') }}</span>
                </div>

                <div class="flex items-center gap-1" data-chat-quick-reaction-row>
                    <div class="rt-chat-quick-reactions-track flex min-w-0 flex-1 gap-1 overflow-x-auto">
                        @foreach ($quick as $emoji)
                            <button
                                type="button"
                                role="menuitem"
                                data-chat-reaction-choice
                                wire:click="{{ $reactMethod }}({{ (int) $messageId }}, @js($emoji))"
                                wire:loading.attr="disabled"
                                wire:target="{{ $reactMethod }}"
                                class="rt-chat-reaction-option flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl text-xl {{ $myReaction === $emoji ? 'is-selected' : '' }}"
                                aria-pressed="{{ $myReaction === $emoji ? 'true' : 'false' }}"
                                aria-label="{{ __('app.chat_react_with', ['emoji' => $emoji]) }}"
                            >{{ $emoji }}</button>
                        @endforeach
                    </div>
                    <button
                        type="button"
                        x-on:click="showMore = ! showMore"
                        x-bind:aria-expanded="showMore.toString()"
                        data-rt-dropdown-keep-open
                        class="rt-chat-reaction-option flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl text-sm"
                        aria-label="{{ __('app.chat_more_reactions') }}"
                    >
                        <i class="far fa-chevron-down transition" x-bind:class="showMore && 'rotate-180'" aria-hidden="true"></i>
                    </button>
                </div>

                <div x-cloak x-show="showMore" style="display:none;" data-chat-expanded-reactions>
                    <div class="rt-chat-reaction-grid mt-1 grid max-h-44 gap-1 overflow-y-auto rounded-xl p-1">
                        @foreach ($extended as $emoji)
                            <button
                                type="button"
                                role="menuitem"
                                data-chat-reaction-choice
                                wire:click="{{ $reactMethod }}({{ (int) $messageId }}, @js($emoji))"
                                wire:loading.attr="disabled"
                                wire:target="{{ $reactMethod }}"
                                class="rt-chat-reaction-option flex min-h-11 min-w-11 items-center justify-center rounded-xl text-xl {{ $myReaction === $emoji ? 'is-selected' : '' }}"
                                aria-pressed="{{ $myReaction === $emoji ? 'true' : 'false' }}"
                                aria-label="{{ __('app.chat_react_with', ['emoji' => $emoji]) }}"
                            >{{ $emoji }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endunless
    </x-slot>
</x-ui.dropdown.anchor-dropdown>
