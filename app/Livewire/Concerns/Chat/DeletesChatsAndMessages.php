<?php

namespace App\Livewire\Concerns\Chat;

use App\Events\ChatMessageDeleted;
use App\Models\ChatLiveLocation;
use App\Models\ChatMessage;
use App\Models\ChatMessageReaction;
use App\Models\ChatMessageView;
use App\Models\File;
use App\Services\Chat\ChatLiveLocationService;
use App\Services\Chat\ChatMessageInteractionService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * Loeschen mit Bestaetigung: eigene Nachrichten (Grabstein statt Entfernen)
 * sowie Chats – Gruppe aufloesen (Verwalter), Gruppe verlassen oder den
 * eigenen Verlauf eines Direktchats ausblenden.
 *
 * request*() merkt das Ziel in einer gesperrten Property, confirm*() fuehrt
 * aus; der Dialog selbst ist der Standarddialog der Oberflaeche.
 */
trait DeletesChatsAndMessages
{
    public bool $showDeleteMessageModal = false;

    #[Locked]
    public ?int $pendingDeleteMessageId = null;

    public bool $showDeleteChatModal = false;

    #[Locked]
    public ?int $pendingDeleteChatId = null;

    public function deleteMessage(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        app(ChatMessageInteractionService::class)->tombstone($chat, auth()->user(), $messageId);
        $chat->touch();

        $this->broadcastChatEvent(new ChatMessageDeleted($chat->id, $messageId));
        $this->dispatch('inbox:refresh');
        $this->dispatch('chat:message-deleted', messageId: $messageId);
    }

    public function requestDeleteMessage(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = $this->visibleMessagesFor($chat)->findOrFail($messageId);

        abort_unless((int) $message->user_id === (int) auth()->id(), 403);

        $this->pendingDeleteMessageId = $message->id;
        $this->showDeleteMessageModal = true;
    }

    public function confirmDeleteMessage(): void
    {
        if (! $this->pendingDeleteMessageId) {
            $this->showDeleteMessageModal = false;

            return;
        }

        $messageId = $this->pendingDeleteMessageId;
        $this->showDeleteMessageModal = false;
        $this->pendingDeleteMessageId = null;

        $this->deleteMessage($messageId);
    }

    public function requestDeleteChat(?int $chatId = null): void
    {
        $targetChatId = $chatId ?? $this->selectedChatId;

        if (! $targetChatId) {
            return;
        }

        $chat = $this->myChat($targetChatId);
        $this->pendingDeleteChatId = $chat->id;
        $this->showDeleteChatModal = true;
    }

    public function confirmDeleteChat(ChatLiveLocationService $liveLocations): void
    {
        if (! $this->pendingDeleteChatId) {
            $this->showDeleteChatModal = false;

            return;
        }

        $chat = $this->myChat($this->pendingDeleteChatId);
        $me = auth()->user();
        $toastKey = 'chat_deleted';

        if ($chat->isGroup() && $chat->canManageGroup($me)) {
            $messageIds = $chat->messages()->withTrashed()->pluck('id');
            $fileIds = File::query()
                ->where('fileable_type', ChatMessage::class)
                ->whereIn('fileable_id', $messageIds)
                ->pluck('id');

            DB::transaction(function () use ($chat, $messageIds): void {
                ChatLiveLocation::query()->whereIn('chat_message_id', $messageIds)->delete();
                ChatMessageView::query()->whereIn('chat_message_id', $messageIds)->delete();
                ChatMessageReaction::query()->whereIn('chat_message_id', $messageIds)->delete();
                ChatMessage::query()->withTrashed()->whereIn('id', $messageIds)->forceDelete();
                $chat->participants()->detach();
                $chat->delete();
            });

            File::query()->whereIn('id', $fileIds)->get()->each->delete();
            $toastKey = 'group_deleted';
        } elseif ($chat->isGroup()) {
            $liveLocations->stopForUserInChat($chat, $me, ChatLiveLocation::STOP_CHAT_LEFT);
            $chat->participants()->detach($me->id);
            $chat->touch();
            $toastKey = 'group_left';
        } else {
            $liveLocations->stopForUserInChat($chat, $me, ChatLiveLocation::STOP_CHAT_LEFT);
            $timestamp = now();
            $chat->participants()->updateExistingPivot($me->id, [
                'last_read_at' => $timestamp,
                'last_opened_at' => null,
                'hidden_at' => $timestamp,
                'cleared_at' => $timestamp,
            ]);
        }

        $deletedChatWasSelected = (int) $this->selectedChatId === (int) $chat->id;

        $this->showDeleteChatModal = false;
        $this->pendingDeleteChatId = null;

        if ($deletedChatWasSelected) {
            $this->resetSelectedChat();
        }

        $this->dispatch('swal:toast', type: 'success', text: __($toastKey === 'chat_deleted' ? 'app.chat_deleted' : 'app.'.$toastKey));
        $this->dispatch('inbox:refresh');
    }
}
