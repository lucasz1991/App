<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChatLiveLocationChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $chatId,
        public int $messageId,
        public string $liveLocationId,
        public int $version,
        public string $status,
        public string $expiresAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'chat.live-location.changed';
    }

    /** @return array{chatId: int, messageId: int, liveLocationId: string, version: int, status: string, expiresAt: string} */
    public function broadcastWith(): array
    {
        // Exact coordinates never leave the authorized message response. A
        // participant can clear history while their channel connection lives
        // on, so the broadcast is intentionally an ID-only refresh signal.
        return [
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'liveLocationId' => $this->liveLocationId,
            'version' => $this->version,
            'status' => $this->status,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
