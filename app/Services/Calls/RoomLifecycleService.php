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
    public function __construct(
        protected LiveKitService $livekit,
        protected CallRecordingService $recordings,
        protected CallConversationService $conversations,
        protected RoomEventRecorder $events,
    ) {
    }

    /**
     * Anruf in einem Chat starten: DB-Raum + Host-Teilnahme + LiveKit-Raum.
     *
     * @param bool $video false = Sprachanruf. Steuert nur, ob die Kamera beim
     *   Beitritt aktiviert wird (calls.js liest settings.video); zuschalten
     *   laesst sie sich im Gespraech jederzeit.
     */
    public function createForChat(Chat $chat, User $owner, bool $video = true): Room
    {
        $room = DB::transaction(function () use ($chat, $owner, $video): Room {
            $room = Room::create([
                'name' => $chat->displayNameFor($owner),
                'type' => $chat->isGroup() ? 'group' : 'direct',
                'status' => 'pending',
                'owner_id' => $owner->id,
                'chat_id' => $chat->id,
                'settings' => ['video' => $video],
            ]);

            $room->participants()->create([
                'user_id' => $owner->id,
                'role' => 'host',
                'connection' => 'invited',
                'livekit_identity' => LiveKitService::identityFor($owner),
            ]);

            $this->conversations->createForRoom($room, $owner);
            $this->events->record($room, 'created', $owner, [
                'type' => $room->type,
                'video' => $video,
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
                'ended_reason' => 'infrastructure_unavailable',
            ])->save();

            $this->events->record($room, 'cancelled', $owner, [
                'reason' => 'infrastructure_unavailable',
            ]);

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

        $this->events->record($room, 'started');
    }

    /** Anruf beenden (UI-Aktion oder Webhook room_finished). */
    public function markEnded(Room $room, string $reason = 'ended'): void
    {
        $this->recordings->requestStop($room);

        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $room->forceFill([
            'status' => 'ended',
            'ended_at' => $room->ended_at ?? now(),
            'ended_reason' => $reason,
        ])->save();

        $this->expirePendingInvitations($room);

        $room->participants()
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);

        $this->events->record($room, 'ended', metadata: ['reason' => $reason]);

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

        $this->recordings->requestStop($room);
        $this->livekit->deleteRoom($room);

        $room->forceFill([
            'status' => 'cancelled',
            'ended_at' => $room->ended_at ?? now(),
            'ended_reason' => $reason,
        ])->save();

        $this->expirePendingInvitations($room);

        $room->participants()
            ->whereIn('connection', ['invited', 'joined'])
            ->update(['connection' => 'left', 'left_at' => now()]);

        $this->events->record($room, 'cancelled', metadata: ['reason' => $reason]);

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

        $this->events->record($room, 'participant_left', $participant->user_id);

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
        $this->recordings->requestStop($room);
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

        $updated = $room->participants()
            ->where('livekit_identity', $identity)
            // 'disconnected' fehlt bewusst: wen die Moderation entfernt hat,
            // darf kein nachlaufender Webhook wieder als verbunden fuehren.
            ->whereIn('connection', ['invited', 'left'])
            ->update(['connection' => 'joined', 'joined_at' => now()]);

        if ($updated > 0) {
            $participant = $room->participants()
                ->where('livekit_identity', $identity)
                ->with('user')
                ->first();

            $room->forceFill([
                'connected_at' => $room->connected_at ?? now(),
            ])->save();

            if ($participant?->user) {
                $this->conversations->attachParticipant($room, $participant->user);
            }

            $this->events->record($room, 'participant_joined', $participant?->user_id, [
                'identity' => $identity,
            ]);
        }

        $this->markActive($room);
    }

    /** Webhook participant_left. */
    public function markParticipantLeft(Room $room, string $identity): void
    {
        if (in_array($room->status, ['ended', 'cancelled'], true)) {
            return;
        }

        $participant = $room->participants()
            ->where('livekit_identity', $identity)
            ->first();

        $updated = $room->participants()
            ->where('livekit_identity', $identity)
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);

        if ($updated > 0) {
            $this->events->record($room, 'participant_left', $participant?->user_id, [
                'identity' => $identity,
            ]);
        }

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
