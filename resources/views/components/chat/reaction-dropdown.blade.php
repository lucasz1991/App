@props([
    'messageId',
    'emoji',
    'count' => 1,
    'names' => '',
    'myReaction' => null,
    'reactMethod' => 'setReaction',
    'removeMethod' => 'removeReaction',
    'dropdownId' => null,
])

@php
    $isMine = $myReaction === $emoji;
    $hasReaction = filled($myReaction);
    $resolvedId = $dropdownId ?: 'chat-reaction-'.$messageId.'-'.md5($emoji);
    $reactionLabel = trans_choice('app.chat_reaction_count', $count, [
        'emoji' => $emoji,
        'count' => $count,
    ]);
@endphp

<x-ui.dropdown.anchor-dropdown
    align="left"
    width="56"
    :offset="4"
    :dropdown-id="$resolvedId"
    :content-label="__('app.chat_reaction_options')"
    wire:key="{{ $resolvedId }}"
    class="shrink-0"
    data-chat-reaction-dropdown
    data-no-chat-swipe
>
    <x-slot name="trigger">
        <button
            type="button"
            class="rt-chat-reaction-chip {{ $isMine ? 'is-mine' : '' }} inline-flex min-h-11 min-w-11 items-center justify-center gap-1 px-0.5 text-sm transition"
            aria-pressed="{{ $isMine ? 'true' : 'false' }}"
            aria-label="{{ $reactionLabel }}"
            @if(filled($names)) title="{{ $names }}" @endif
        >
            <span aria-hidden="true">{{ $emoji }}</span>
            @if ($count > 1)<span class="text-[10px] font-extrabold tabular-nums">{{ $count }}</span>@endif
        </button>
    </x-slot>

    <x-slot name="content">
        <div class="space-y-0.5 p-1.5">
            @if ($isMine)
                <x-ui.dropdown.dropdown-link x-on:click="openReactionMenu()">
                    <span class="rt-chat-option-icon"><i class="far fa-rotate-right" aria-hidden="true"></i></span>
                    <span>{{ __('app.chat_change_reaction') }}</span>
                </x-ui.dropdown.dropdown-link>
                <div class="rt-chat-option-divider my-1" role="separator"></div>
                <x-ui.dropdown.dropdown-link
                    wire:click="{{ $removeMethod }}({{ (int) $messageId }})"
                    data-rt-tone="danger"
                >
                    <span class="rt-chat-option-icon"><i class="far fa-trash-alt" aria-hidden="true"></i></span>
                    <span>{{ __('app.chat_remove_reaction') }}</span>
                </x-ui.dropdown.dropdown-link>
            @else
                <x-ui.dropdown.dropdown-link wire:click="{{ $reactMethod }}({{ (int) $messageId }}, @js($emoji))">
                    <span class="rt-chat-option-icon"><span aria-hidden="true">{{ $emoji }}</span></span>
                    <span>{{ $hasReaction ? __('app.chat_change_to_reaction', ['emoji' => $emoji]) : __('app.chat_react_with', ['emoji' => $emoji]) }}</span>
                </x-ui.dropdown.dropdown-link>
                <x-ui.dropdown.dropdown-link x-on:click="openReactionMenu()">
                    <span class="rt-chat-option-icon"><i class="far fa-face-smile" aria-hidden="true"></i></span>
                    <span>{{ __('app.chat_choose_other_reaction') }}</span>
                </x-ui.dropdown.dropdown-link>
            @endif
        </div>
    </x-slot>
</x-ui.dropdown.anchor-dropdown>
