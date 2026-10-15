@section('title', __('app.communication'))
<div data-communication-entry data-tab="{{ $tab }}" data-chat-id="{{ $chatId }}" data-room-uuid="{{ $roomUuid }}" data-history-room-uuid="{{ $historyRoomUuid }}" class="min-h-full">
    <span class="sr-only">{{ __('app.communication') }}</span>
    <noscript>Bitte JavaScript aktivieren, um Chats und Anrufe zu öffnen.</noscript>
</div>
