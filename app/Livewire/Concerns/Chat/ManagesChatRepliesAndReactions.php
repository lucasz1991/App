<?php

namespace App\Livewire\Concerns\Chat;

use App\Events\ChatMessageReactionChanged;
use App\Services\Chat\ChatMessageInteractionService;
use Livewire\Attributes\Locked;

/**
 * Antworten und Reaktionen auf Nachrichten des offenen Chats. Ziel ist immer
 * eine fuer den Nutzer sichtbare Nachricht desselben Chats; die Pruefung
 * liegt im ChatMessageInteractionService.
 */
trait ManagesChatRepliesAndReactions
{
    #[Locked]
    public ?int $replyingToMessageId = null;

    public function beginReply(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $message = app(ChatMessageInteractionService::class)
            ->visibleMessage($chat, auth()->user(), $messageId);

        $this->replyingToMessageId = $message->id;
        $this->dispatch('chat:reply-started');
    }

    public function cancelReply(): void
    {
        $this->replyingToMessageId = null;
    }

    public function toggleReaction(int $messageId, string $emoji): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $reaction = app(ChatMessageInteractionService::class)
            ->toggleReaction($chat, auth()->user(), $messageId, $emoji);

        $this->broadcastChatEvent(new ChatMessageReactionChanged(
            $chat->id,
            $messageId,
            (int) auth()->id(),
            $reaction?->emoji,
        ));
    }

    public function setReaction(int $messageId, string $emoji): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        $reaction = app(ChatMessageInteractionService::class)
            ->setReaction($chat, auth()->user(), $messageId, $emoji);

        $this->broadcastChatEvent(new ChatMessageReactionChanged(
            $chat->id,
            $messageId,
            (int) auth()->id(),
            $reaction->emoji,
        ));
    }

    public function removeReaction(int $messageId): void
    {
        if (! $this->selectedChatId) {
            return;
        }

        $chat = $this->myChat($this->selectedChatId);
        app(ChatMessageInteractionService::class)
            ->removeReaction($chat, auth()->user(), $messageId);

        $this->broadcastChatEvent(new ChatMessageReactionChanged(
            $chat->id,
            $messageId,
            (int) auth()->id(),
            null,
        ));
    }
}
