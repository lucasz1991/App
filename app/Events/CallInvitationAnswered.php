<?php

namespace App\Events;

use App\Models\RoomInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInvitationAnswered implements ShouldBroadcastNow
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
        return 'call.answered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'roomUuid' => (string) $this->invitation->room->uuid,
            'inviteeId' => (int) $this->invitation->invitee_id,
            'inviteeName' => (string) ($this->invitation->invitee?->name ?? ''),
            'status' => (string) $this->invitation->status,
        ];
    }
}
