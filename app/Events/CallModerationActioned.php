<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reine UI-Rueckmeldung: Die Durchsetzung passiert bereits serverseitig an
 * der SFU (RoomServiceClient) – Reverb spiegelt nur fuer die Oberflaeche.
 */
class CallModerationActioned implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Room $room,
        public int $targetUserId,
        public string $action, // mute|unmute|remove|role
        public ?string $value = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('call.'.$this->room->uuid);
    }

    public function broadcastAs(): string
    {
        return 'call.moderated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'roomUuid' => (string) $this->room->uuid,
            'targetUserId' => $this->targetUserId,
            'action' => $this->action,
            'value' => $this->value,
        ];
    }
}
