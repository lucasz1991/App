<?php

namespace App\Services\Calls;

use App\Events\CallEnded;
use App\Events\CallStarted;
use App\Models\Chat;
use App\Models\Room;
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

        $this->livekit->createRoom($room);

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

        // Offene Einladungen gelten als verpasst.
        $room->invitations()
            ->where('status', 'pending')
            ->update(['status' => 'missed', 'responded_at' => now()]);

        $room->participants()
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);

        broadcast(new CallEnded($room, $reason))->toOthers();
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
        $room->participants()
            ->where('livekit_identity', $identity)
            ->whereIn('connection', ['invited', 'left', 'disconnected'])
            ->update(['connection' => 'joined', 'joined_at' => now()]);

        $this->markActive($room);
    }

    /** Webhook participant_left. */
    public function markParticipantLeft(Room $room, string $identity): void
    {
        $room->participants()
            ->where('livekit_identity', $identity)
            ->where('connection', 'joined')
            ->update(['connection' => 'left', 'left_at' => now()]);
    }
}
