<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ChatMessage $message)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.' . $this->message->chat_id);
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'chatId' => (int) $this->message->chat_id,
            'messageId' => (int) $this->message->id,
            'senderId' => (int) $this->message->user_id,
        ];
    }
}
