<?php

namespace App\Services\Calls;

use App\Models\Chat;
use App\Models\Room;
use App\Models\User;

class CallConversationService
{
    /**
     * Create the isolated conversation that belongs to one call only.
     * The caller is responsible for wrapping this in the room transaction.
     */
    public function createForRoom(Room $room, User $owner): Chat
    {
        if ($room->call_chat_id) {
            return $room->callChat()->firstOrFail();
        }

        $chat = Chat::create([
            'type' => 'call',
            'name' => $room->name,
            'created_by' => $owner->id,
        ]);

        $joinedAt = $room->created_at ?? now();
        $chat->participants()->attach($owner->id, [
            'last_read_at' => $joinedAt,
            'last_opened_at' => $joinedAt,
            'joined_at' => $joinedAt,
            'hidden_at' => null,
            'cleared_at' => null,
        ]);

        $room->forceFill(['call_chat_id' => $chat->id])->save();
        $room->setRelation('callChat', $chat);

        return $chat;
    }

    /**
     * Only an accepted or actually joining user is attached. The visibility
     * boundary deliberately starts at room creation so real participants can
     * read the complete call transcript afterwards.
     */
    public function attachParticipant(Room $room, User $user): void
    {
        $chat = $room->callChat()->first();

        if (! $chat) {
            return;
        }

        $joinedAt = $room->created_at ?? now();
        $existing = $chat->participantFor($user);

        if ($existing) {
            $chat->participants()->updateExistingPivot($user->id, [
                'joined_at' => $existing->pivot?->joined_at ?? $joinedAt,
                'hidden_at' => null,
            ]);

            return;
        }

        $chat->participants()->attach($user->id, [
            'last_read_at' => null,
            'last_opened_at' => null,
            'joined_at' => $joinedAt,
            'hidden_at' => null,
            'cleared_at' => null,
        ]);
    }
}
