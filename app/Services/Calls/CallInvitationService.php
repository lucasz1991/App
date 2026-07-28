<?php

namespace App\Services\Calls;

use App\Events\CallInvitationAnswered;
use App\Events\CallInvitationExpired;
use App\Events\CallInvitationSent;
use App\Jobs\ExpireCallInvitation;
use App\Models\Room;
use App\Models\RoomInvitation;
use App\Models\User;
use App\Support\Push\PushDelivery;
use Illuminate\Support\Collection;

/**
 * Einladungs- und Klingel-Logik: DB-Zeile + Reverb-Broadcast + Web-Push +
 * verzoegertes Ring-Timeout auf der Database-Queue.
 */
class CallInvitationService
{
    public function __construct(protected PushDelivery $push)
    {
    }

    /**
     * @param Collection<int, User> $invitees
     * @return Collection<int, RoomInvitation>
     */
    public function invite(Room $room, User $inviter, Collection $invitees): Collection
    {
        return $invitees
            ->reject(fn (User $user): bool => (int) $user->id === (int) $inviter->id)
            ->filter(fn (User $user): bool => $user->hasRbacPermission('calls.join') || $user->isAdmin())
            ->map(fn (User $user): RoomInvitation => $this->inviteOne($room, $inviter, $user))
            ->values();
    }

    public function inviteOne(Room $room, User $inviter, User $invitee): RoomInvitation
    {
        $expiresAt = now()->addSeconds((int) config('livekit.ring_timeout'));

        $invitation = $room->invitations()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        $room->participants()->firstOrCreate(
            ['user_id' => $invitee->id],
            [
                'role' => 'speaker',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($invitee),
            ],
        );

        broadcast(new CallInvitationSent($invitation))->toOthers();

        $this->push->incomingCall($invitation);

        ExpireCallInvitation::dispatch($invitation->id)->delay($expiresAt);

        return $invitation;
    }

    public function accept(RoomInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            return;
        }

        $invitation->forceFill(['status' => 'accepted', 'responded_at' => now()])->save();

        broadcast(new CallInvitationAnswered($invitation))->toOthers();
    }

    public function decline(RoomInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            return;
        }

        $invitation->forceFill(['status' => 'declined', 'responded_at' => now()])->save();

        broadcast(new CallInvitationAnswered($invitation))->toOthers();
    }

    /** Ring-Timeout abgelaufen oder Anrufer hat vorher aufgelegt. */
    public function expire(RoomInvitation $invitation, string $status = 'missed'): void
    {
        if (! $invitation->isPending()) {
            return;
        }

        $invitation->forceFill(['status' => $status, 'responded_at' => now()])->save();

        broadcast(new CallInvitationExpired($invitation))->toOthers();
    }
}
