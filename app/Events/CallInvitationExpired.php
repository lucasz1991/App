<?php

namespace App\Events;

use App\Models\RoomInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Stoppt das Klingeln in allen offenen Tabs des Eingeladenen. */
class CallInvitationExpired implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public RoomInvitation $invitation)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->invitation->invitee_id);
    }

    public function broadcastAs(): string
    {
        return 'call.missed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'invitationId' => (int) $this->invitation->id,
            'roomUuid' => (string) $this->invitation->room->uuid,
            'status' => (string) $this->invitation->status,
        ];
    }
}
