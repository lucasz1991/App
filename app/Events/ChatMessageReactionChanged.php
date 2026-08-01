<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageReactionChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $chatId,
        public int $messageId,
        public int $userId,
        public ?string $emoji,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'chat.message.reaction';
    }

    /** @return array{chatId: int, messageId: int, userId: int, emoji: string|null} */
    public function broadcastWith(): array
    {
        return [
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'userId' => $this->userId,
            'emoji' => $this->emoji,
        ];
    }
}
