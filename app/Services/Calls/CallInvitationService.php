<?php

namespace App\Services\Calls;

use App\Events\CallInvitationAnswered;
use App\Events\CallInvitationExpired;
use App\Events\CallInvitationRinging;
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

        $participant = $room->participants()->firstOrCreate(
            ['user_id' => $invitee->id],
            [
                'role' => 'speaker',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($invitee),
            ],
        );

        // Eine erneute Einladung oeffnet eine beendete oder von der Moderation
        // entzogene Teilnahme wieder – sonst sperrt Room::mayJoin() dauerhaft.
        if (! $participant->wasRecentlyCreated
            && in_array($participant->connectionState(), ['left', 'disconnected'], true)) {
            $participant->forceFill([
                'connection' => 'invited',
                'left_at' => null,
                'role' => $participant->role === 'viewer' ? 'speaker' : $participant->role,
            ])->save();
        }

        broadcast(new CallInvitationSent($invitation))->toOthers();

        $this->push->incomingCall($invitation);

        ExpireCallInvitation::dispatch($invitation->id)->delay($expiresAt);

        return $invitation;
    }

    /**
     * Rueckmeldung der Gegenseite: das Geraet laeutet wirklich.
     *
     * Kommt entweder aus einer offenen Browser-Sitzung (das Klingel-Overlay
     * meldet sich selbst) oder aus dem Service Worker der installierten App,
     * sobald er die Anruf-Benachrichtigung anzeigt. Nur die ERSTE Meldung
     * zaehlt: sonst wuerden mehrere Geraete desselben Nutzers denselben
     * Zustand mehrfach broadcasten.
     *
     * @param string $via 'browser' (offene Sitzung) oder 'app' (Service Worker)
     * @return bool true, wenn dies die erste Klingel-Meldung war.
     */
    public function markRinging(RoomInvitation $invitation, string $via = 'browser'): bool
    {
        if (! $invitation->isPending() || $invitation->isExpired() || $invitation->isRinging()) {
            return false;
        }

        $invitation->forceFill([
            'ringing_at' => now(),
            'ringing_via' => in_array($via, ['browser', 'app'], true) ? $via : 'browser',
        ])->save();

        broadcast(new CallInvitationRinging($invitation));

        return true;
    }

    /**
     * Einladung annehmen.
     *
     * @param bool $allowExpired Nur fuer Einstiegswege, bei denen der Nutzer
     *   nachweislich schon im Anruf-Fenster steht (Push-Deep-Link). Ueber das
     *   Klingel-Overlay bleibt eine abgelaufene Einladung unbeantwortbar –
     *   sonst laesst sich ein laengst verpasster Anruf noch annehmen, wenn der
     *   Queue-Worker den Ring-Timeout nicht ausgefuehrt hat.
     *
     * @return bool true, wenn die Einladung jetzt 'accepted' ist.
     */
    public function accept(RoomInvitation $invitation, bool $allowExpired = false): bool
    {
        if (! $invitation->isPending()) {
            return false;
        }

        if ($invitation->isExpired() && ! $allowExpired) {
            $this->expire($invitation);

            return false;
        }

        $invitation->forceFill(['status' => 'accepted', 'responded_at' => now()])->save();

        broadcast(new CallInvitationAnswered($invitation))->toOthers();

        return true;
    }

    public function decline(RoomInvitation $invitation): bool
    {
        if (! $invitation->isPending()) {
            return false;
        }

        if ($invitation->isExpired()) {
            $this->expire($invitation);

            return false;
        }

        $invitation->forceFill(['status' => 'declined', 'responded_at' => now()])->save();

        broadcast(new CallInvitationAnswered($invitation))->toOthers();

        return true;
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
