<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallEnded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Room $room, public string $reason = 'ended')
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('call.'.$this->room->uuid)];

        if ($this->room->chat_id) {
            $channels[] = new PrivateChannel('chat.'.$this->room->chat_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'call.ended';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'roomUuid' => (string) $this->room->uuid,
            'chatId' => $this->room->chat_id ? (int) $this->room->chat_id : null,
            'reason' => $this->reason,
        ];
    }
}
