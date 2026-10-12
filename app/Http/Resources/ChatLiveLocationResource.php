<?php

namespace App\Http\Resources;

use App\Models\ChatLiveLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatLiveLocation */
class ChatLiveLocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $message = $this->message;
        $owner = (int) $request->user()?->id === (int) $this->user_id;

        return [
            'id' => $this->uuid,
            'message_id' => (int) $this->chat_message_id,
            'chat_id' => (int) $message->chat_id,
            'duration_minutes' => (int) $this->duration_minutes,
            'status' => $this->status(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'stopped_at' => $this->stopped_at?->toIso8601String(),
            'last_position_at' => $this->last_position_at?->toIso8601String(),
            'version' => (int) $this->version,
            'position' => $this->position(),
            'profile_history_consent' => $owner && $this->profileHistoryEnabled(),
            'routes' => [
                'update' => route('chat.live-locations.update', $this->resource),
                'stop' => route('chat.live-locations.destroy', $this->resource),
                'profile_history' => $owner ? route('chat.live-locations.profile-history.destroy', $this->resource) : null,
                'native_status' => $owner ? route('chat.live-locations.native-status', $this->resource) : null,
                'native_handoff' => $owner ? route('chat.live-locations.native-start', $this->resource) : null,
            ],
        ];
    }
}
