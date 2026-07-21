<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ChatMessage $message)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->message->chat
            ->participants()
            ->where('users.id', '!=', $this->message->user_id)
            ->pluck('users.id')
            ->map(fn ($userId) => new PrivateChannel('App.Models.User.' . $userId))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'chat.message.received';
    }

    /** @return array<string, int|string|null> */
    public function broadcastWith(): array
    {
        return [
            'chatId' => (int) $this->message->chat_id,
            'messageId' => (int) $this->message->id,
            'from' => $this->message->sender?->name,
        ];
    }
}
