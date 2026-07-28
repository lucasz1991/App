<?php

namespace App\Services\Calls;

use App\Events\CallEnded;
use App\Events\CallInvitationExpired;
use App\Events\CallStarted;
use App\Models\Chat;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Einziger Schreiber fuer rooms.status.
 *
 * Wird sowohl von Livewire-Aktionen als auch von LiveKit-Webhooks aufgerufen;
 * alle Uebergaenge sind idempotent, da Webhooks wiederholt zugestellt werden
 * koennen und UI-Aktion und Webhook sich zeitlich ueberholen.
 */
class RoomLifecycleService
{
    public function __construct(protected LiveKitService $livekit)
    {
    }

    /** Anruf in einem Chat starten: DB-Raum + Host-Teilnahme + LiveKit-Raum. */
    public function createForChat(Chat $chat, User $owner): Room
    {
        $room = DB::transaction(function () use ($chat, $owner): Room {
            $room = Room::create([
                'name' => $chat->displayNameFor($owner),
                'type' => $chat->isGroup() ? 'group' : 'direct',
                'status' => 'pending',
                'owner_id' => $owner->id,
                'chat_id' => $chat->id,
                'settings' => ['video' => true],
            ]);

            $room->participants()->create([
                'user_id' => $owner->id,
                'role' => 'host',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($owner),
            ]);

            return $room;
        });

        // Schlaegt das fehl, darf der Anrufer nicht in ein Fenster geleitet
        // werden, dessen Raum an der SFU nicht existiert. Der DB-Raum wird
        // sofort zurueckgenommen, damit er den Chat nicht dauerhaft belegt.
        if (! $this->livekit->createRoom($room)) {
            $room->forceFill([
                'status' => 'cancelled',
                'ended_at' => now(),
            ])->save();

            throw new CallInfrastructureUnavailable(
                'LiveKit konnte den Raum '.$room->uuid.' nicht anlegen.',
            );
        }

        broadcast(new CallStarted($room))->toOthers();

        return $room;
    }

    /** Webhook room_started bzw. erster Beitritt. */
    public function markActive(Room $room): void
    {
        if (! in_array($room->status, ['pending'], true)) {
            return;
        }

        $room->forceFill([
            'status' => 'active',
            'started_at' => $room->started_at ?? now(),
        ])->save();
    }

    /** Anruf beenden (UI-Aktion oder Webhook room_finished). */
    public function markEnded(Room $room, string $reason = 'ended'): void
    {
        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $room->forceFill([
            'status' => 'ended',
            'ended_at' => $room->ended_at ?? now(),
        ])->save();

        $this->expirePendingInvitations($room);

        $room->participants()
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);

        broadcast(new CallEnded($room, $reason))->toOthers();
    }

    /**
     * Anruf abbrechen, bevor jemand verbunden war (Anrufer legt waehrend des
     * Klingelns auf). Anders als markEnded() raeumt das zuerst die SFU ab und
     * stoppt das Klingeln bei allen Eingeladenen sofort.
     */
    public function cancel(Room $room, string $reason = 'cancelled'): void
    {
        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $this->livekit->deleteRoom($room);

        $room->forceFill([
            'status' => 'cancelled',
            'ended_at' => $room->ended_at ?? now(),
        ])->save();

        $this->expirePendingInvitations($room);

        $room->participants()
            ->whereIn('connection', ['invited', 'joined'])
            ->update(['connection' => 'left', 'left_at' => now()]);

        broadcast(new CallEnded($room, $reason))->toOthers();
    }

    /**
     * Selbst auflegen. Ist danach niemand mehr verbunden, endet der Anruf –
     * legt der Host noch waehrend des Klingelns auf, wird er abgebrochen.
     */
    public function leave(Room $room, RoomParticipant $participant): void
    {
        $othersConnected = $room->participants()
            ->whereKeyNot($participant->getKey())
            ->where('connection', 'joined')
            ->exists();

        if (! $othersConnected && $participant->isModerator()) {
            $this->cancel($room);

            return;
        }

        $participant->forceFill([
            'connection' => 'left',
            'left_at' => now(),
        ])->save();

        if (! $othersConnected) {
            $this->markEnded($room, 'empty');
        }
    }

    /**
     * Offene Einladungen verfallen lassen – einzeln, damit jede ihr
     * CallInvitationExpired sendet und das Klingeln sofort aufhoert. Ein
     * Massen-UPDATE waere still und liesse die Geraete weiterklingeln.
     */
    protected function expirePendingInvitations(Room $room): void
    {
        $room->invitations()
            ->where('status', 'pending')
            ->get()
            ->each(function ($invitation): void {
                $invitation->forceFill([
                    'status' => 'missed',
                    'responded_at' => now(),
                ])->save();

                broadcast(new CallInvitationExpired($invitation))->toOthers();
            });
    }

    /** Aktives Beenden durch einen Moderator/Host: erst SFU, dann DB. */
    public function endCall(Room $room, string $reason = 'ended'): void
    {
        $this->livekit->deleteRoom($room);
        $this->markEnded($room, $reason);
    }

    /** Webhook participant_joined. */
    public function markParticipantJoined(Room $room, string $identity): void
    {
        // LiveKit wiederholt Webhooks und kann sie verspaetet oder verdreht
        // zustellen: ein Nachzuegler darf einen beendeten Raum nicht wiederbeleben.
        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $room->participants()
            ->where('livekit_identity', $identity)
            // 'disconnected' fehlt bewusst: wen die Moderation entfernt hat,
            // darf kein nachlaufender Webhook wieder als verbunden fuehren.
            ->whereIn('connection', ['invited', 'left'])
            ->update(['connection' => 'joined', 'joined_at' => now()]);

        $this->markActive($room);
    }

    /** Webhook participant_left. */
    public function markParticipantLeft(Room $room, string $identity): void
    {
        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $room->participants()
            ->where('livekit_identity', $identity)
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);

        // War das der Letzte, ist der Anruf vorbei. Ohne diesen Schritt bliebe
        // der Raum aktiv und Chat::activeRoom() wuerde jeden neuen Anruf im
        // selben Chat in den toten Raum umleiten.
        $stillConnected = $room->participants()
            ->where('connection', 'joined')
            ->exists();

        if (! $stillConnected && $room->isActive()) {
            $this->markEnded($room, 'empty');
        }
    }
}
