<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CallTimelineChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $chatId, public int $roomId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'call.timeline.changed';
    }

    public function broadcastWith(): array
    {
        return ['chatId' => $this->chatId, 'roomId' => $this->roomId];
    }
}
