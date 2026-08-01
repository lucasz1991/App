<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatMessageReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatMessageInteractionService
{
    /** @var list<string> */
    public const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /** @var list<string> */
    public const ALLOWED_REACTIONS = [
        '😀', '😃', '😄', '😁', '😊', '😍', '🥰', '😘', '😎', '🤔',
        '🙄', '😬', '😢', '😭', '😡', '🤯', '😴', '👏', '🙌', '👍',
        '👎', '❤️', '💔', '🔥', '🎉', '✅', '👀', '🙏', '💯',
    ];

    public function visibleMessage(
        Chat $chat,
        User $user,
        int $messageId,
        bool $withDeleted = false,
    ): ChatMessage {
        if (! $chat->relationLoaded('participants')) {
            $chat->load('participants');
        }

        $participant = $chat->participantFor($user);
        abort_unless($participant && ! $participant->pivot?->hidden_at, 403);

        $visibleSince = $chat->visibleSinceFor($user);
        $query = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->when($withDeleted, fn ($query) => $query->withTrashed())
            ->when($visibleSince, fn ($query) => $query->where('created_at', '>=', $visibleSince));

        return $query->findOrFail($messageId);
    }

    public function replyTarget(Chat $chat, User $user, ?int $messageId): ?ChatMessage
    {
        return $messageId ? $this->visibleMessage($chat, $user, $messageId) : null;
    }

    public function toggleReaction(
        Chat $chat,
        User $user,
        int $messageId,
        string $emoji,
    ): ?ChatMessageReaction {
        abort_unless(in_array($emoji, self::ALLOWED_REACTIONS, true), 422);

        $message = $this->visibleMessage($chat, $user, $messageId);
        abort_if((int) $message->user_id === (int) $user->id, 403);

        return DB::transaction(function () use ($message, $user, $emoji): ?ChatMessageReaction {
            $reaction = ChatMessageReaction::query()
                ->where('chat_message_id', $message->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($reaction?->emoji === $emoji) {
                $reaction->delete();

                return null;
            }

            if ($reaction) {
                $reaction->update(['emoji' => $emoji]);

                return $reaction->refresh();
            }

            return ChatMessageReaction::create([
                'chat_message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        });
    }

    public function tombstone(Chat $chat, User $user, int $messageId): ChatMessage
    {
        $message = $this->visibleMessage($chat, $user, $messageId);
        abort_unless((int) $message->user_id === (int) $user->id, 403);

        return DB::transaction(function () use ($message): ChatMessage {
            $message->loadMissing('files');
            $message->files->each->delete();
            $message->views()->delete();
            $message->reactions()->delete();
            $message->forceFill([
                'body' => '',
                'message_type' => 'deleted',
                'view_once' => false,
                'voice_duration_seconds' => null,
                'voice_waveform' => null,
            ])->save();
            $message->delete();

            return $message;
        });
    }
}
