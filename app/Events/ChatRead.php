<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChatRead implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $chatId,
        public int $userId,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.' . $this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'chatId' => $this->chatId,
            'userId' => $this->userId,
        ];
    }
}
