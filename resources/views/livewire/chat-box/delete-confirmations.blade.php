{{--
    Chat: Bruecken fuer die Bestaetigungen „Nachricht loeschen“ und „Chat loeschen/Gruppe verlassen“.
    Erwartet: $pendingDeleteChat, $selectedChat, $me.
--}}
{{-- Bestaetigungen laufen ueber den Standarddialog x-ui.confirmation-dialog:
     requestDelete*() setzt die Property, rtConfirmBridge oeffnet den Dialog. --}}
<div
    id="rt-delete-chat-message-modal"
    hidden
    wire:key="rt-delete-chat-message-confirm"
    x-data="rtConfirmBridge('showDeleteMessageModal', 'confirmDeleteMessage')"
    data-confirm-title="{{ __('app.delete_message_title') }}"
    data-confirm-message="{{ __('app.delete_message_hint') }}"
    data-confirm-label="{{ __('app.delete') }}"
></div>

@php
    $deleteTargetChat = $pendingDeleteChat ?? $selectedChat;
    $deleteTargetLeaves = $deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me);
@endphp
<div
    id="rt-delete-chat-modal"
    hidden
    wire:key="rt-delete-chat-confirm"
    x-data="rtConfirmBridge('showDeleteChatModal', 'confirmDeleteChat')"
    data-confirm-title="{{ $deleteTargetLeaves ? __('app.leave_group') : ($deleteTargetChat?->isGroup() ? __('app.delete_group') : __('app.delete_chat')) }}"
    data-confirm-message="{{ $deleteTargetLeaves ? __('app.leave_group_hint') : ($deleteTargetChat?->isGroup() ? __('app.delete_group_hint') : __('app.delete_direct_chat_hint')) }}"
    data-confirm-label="{{ $deleteTargetLeaves ? __('app.leave_group') : __('app.delete') }}"
></div>
