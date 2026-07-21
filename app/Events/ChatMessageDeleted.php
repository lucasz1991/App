<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $chatId, public int $messageId)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.' . $this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
        ];
    }
}
