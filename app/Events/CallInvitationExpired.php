<?php

namespace App\Events;

use App\Models\RoomInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stoppt das Klingeln in allen offenen Tabs des Eingeladenen — und nimmt die
 * Person im Fenster des Anrufers aus der Warteliste, sonst bliebe dort "wird
 * angerufen" mitsamt Freizeichen stehen, obwohl niemand mehr klingelt.
 */
class CallInvitationExpired implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public RoomInvitation $invitation)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->invitation->invitee_id),
            new PrivateChannel('call.'.$this->invitation->room->uuid),
        ];
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
