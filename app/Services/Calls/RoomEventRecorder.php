<?php

namespace App\Services\Calls;

use App\Models\Room;
use App\Models\RoomEvent;
use App\Models\User;

class RoomEventRecorder
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function record(
        Room $room,
        string $type,
        User|int|null $actor = null,
        array $metadata = [],
        ?string $externalId = null,
    ): RoomEvent {
        $userId = $actor instanceof User ? $actor->id : $actor;

        if ($externalId) {
            $event = RoomEvent::firstOrCreate(
                ['external_id' => $externalId],
                [
                    'room_id' => $room->id,
                    'user_id' => $userId,
                    'type' => $type,
                    'metadata' => $metadata ?: null,
                    'occurred_at' => now(),
                ],
            );
            if ($event->wasRecentlyCreated) {
                app(CallTimelineService::class)->changed($room);
            }

            return $event;
        }

        $event = $room->events()->create([
            'user_id' => $userId,
            'type' => $type,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);

        app(CallTimelineService::class)->changed($room);

        return $event;
    }
}
