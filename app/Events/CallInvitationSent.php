<?php

namespace App\Events;

use App\Models\RoomInvitation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInvitationSent implements ShouldBroadcastNow
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
        return 'call.invited';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $room = $this->invitation->room;

        return [
            'invitationId' => (int) $this->invitation->id,
            'roomUuid' => (string) $room->uuid,
            'roomName' => (string) $room->name,
            'roomType' => (string) $room->type,
            'chatId' => $room->chat_id ? (int) $room->chat_id : null,
            'callerId' => (int) $this->invitation->inviter_id,
            'callerName' => (string) ($this->invitation->inviter?->name ?? ''),
            'callerAvatar' => $this->invitation->inviter?->profile_photo_url,
            'expiresAt' => $this->invitation->expires_at?->toIso8601String(),
        ];
    }
}
