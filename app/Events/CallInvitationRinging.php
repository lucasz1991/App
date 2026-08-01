<?php

namespace App\Events;

use App\Models\RoomInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Die Gegenseite meldet zurueck, dass ihr Geraet tatsaechlich laeutet.
 *
 * Erst dadurch kann das Anruf-Fenster "klingelt" statt nur "wird angerufen"
 * zeigen: eine verschickte Einladung sagt noch nichts darueber aus, ob sie
 * jemanden erreicht hat.
 */
class CallInvitationRinging implements ShouldBroadcastNow
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
            new PrivateChannel('App.Models.User.'.$this->invitation->inviter_id),
            new PrivateChannel('call.'.$this->invitation->room->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.ringing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'roomUuid' => (string) $this->invitation->room->uuid,
            'invitationId' => (int) $this->invitation->id,
            'inviteeId' => (int) $this->invitation->invitee_id,
            'inviteeName' => (string) ($this->invitation->invitee?->name ?? ''),
            'via' => (string) ($this->invitation->ringing_via ?? 'browser'),
        ];
    }
}
