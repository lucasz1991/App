<?php

namespace App\Services\Calls;

use App\Events\CallTimelineChanged;
use App\Models\ChatMessage;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CallTimelineService
{
    public function ready(): bool
    {
        return Schema::hasColumn('chat_messages', 'call_room_id');
    }

    /** New call starts only; deliberately no historical-import side effect. */
    public function ensureForRoom(Room $room): ?ChatMessage
    {
        if (! $room->chat_id || ! $this->ready()) {
            return null;
        }

        return DB::transaction(function () use ($room): ChatMessage {
            $current = Room::query()->lockForUpdate()->findOrFail($room->id);
            $chat = $current->chat()->firstOrFail();
            abort_if($chat->isCall(), 422);
            $existing = ChatMessage::withTrashed()->where('call_room_id', $current->id)->first();
            if ($existing) {
                abort_unless((int) $existing->chat_id === (int) $chat->id && $existing->isCall(), 409);

                return $existing;
            }

            $message = new ChatMessage;
            $message->forceFill([
                'chat_id' => $chat->id,
                'user_id' => $current->owner_id,
                'call_room_id' => $current->id,
                'message_type' => 'call',
                'body' => $current->startsWithVideo() ? 'Videoanruf' : 'Sprachanruf',
                'created_at' => $current->created_at,
                'updated_at' => $current->created_at,
            ])->save();
            $chat->touch();
            $this->changed($current);

            return $message;
        });
    }

    /** No new message/push/unread event when only the call state changes. */
    public function changed(Room $room): void
    {
        if (! $room->chat_id || ! $this->ready()) {
            return;
        }
        $chatId = (int) $room->chat_id;
        $roomId = (int) $room->id;
        DB::afterCommit(static function () use ($chatId, $roomId): void {
            try {
                CallTimelineChanged::dispatch($chatId, $roomId);
            } catch (\Throwable $exception) {
                Log::notice('Call timeline signal unavailable.', ['error_class' => $exception::class]);
            }
        });
    }

    /** Safe original-chat projection: private call-chat contents stay separate. */
    public function projectionFor(ChatMessage $message, User $viewer): array
    {
        abort_unless($message->isCall(), 422);
        $chat = $message->relationLoaded('chat') ? $message->chat : $message->chat()->with('participants')->firstOrFail();
        $chat->loadMissing('participants');
        $member = $chat->participantFor($viewer);
        $visibleSince = $chat->visibleSinceFor($viewer);
        abort_unless($member && ! $member->pivot?->hidden_at
            && (! $visibleSince || $message->created_at->gte($visibleSince)), 403);
        $room = $message->callRoom;
        abort_unless($room && (int) $room->chat_id === (int) $chat->id, 404);
        $participant = $room->participantFor($viewer);
        $invitation = $room->relationLoaded('invitations')
            ? $room->invitations->where('invitee_id', $viewer->id)->sortByDesc('id')->first()
            : $room->invitations()->where('invitee_id', $viewer->id)->latest('id')->first();
        $status = $room->isActive() ? ($room->connected_at ? 'active' : 'ringing') : 'ended';
        if ($room->ended_reason === 'infrastructure_unavailable' || $room->ended_reason === 'recording_failed') {
            $status = 'failed';
        } elseif ($participant?->isRemoved()) {
            $status = 'removed';
        } elseif ($invitation?->status === 'declined') {
            $status = 'declined';
        } elseif (in_array($invitation?->status, ['missed', 'expired'], true)) {
            $status = $room->status === 'cancelled' ? 'cancelled' : 'missed';
        } elseif (! $room->isActive() && $room->status === 'cancelled') {
            $status = 'cancelled';
        } elseif (! $room->isActive() && ! $room->connected_at) {
            $status = 'not_connected';
        } elseif (! $room->isActive() && $invitation?->status === 'accepted' && ! $participant?->joined_at) {
            $status = 'not_connected';
        }
        $estimated = $room->relationLoaded('events')
            ? $room->events->contains('external_id', 'backfill:room:'.$room->id.':created')
            : $room->events()->where('external_id', 'backfill:room:'.$room->id.':created')->exists();
        $canUseCalls = $viewer->status && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.join'));
        $callMember = $room->relationLoaded('callChat') && $room->callChat?->relationLoaded('participants')
            ? $room->callChat->participants->firstWhere('id', $viewer->id) : null;
        $canOpenHistory = $room->relationLoaded('callChat') && (! $room->callChat || $room->callChat->relationLoaded('participants'))
            ? (bool) ($callMember && ! $callMember->pivot?->hidden_at)
            : $room->callChat()->whereHas('participants', fn ($query) => $query->where('users.id', $viewer->id)->whereNull('chat_user.hidden_at'))->exists();

        return [
            'room_id' => (int) $room->id,
            'room_uuid' => (string) $room->uuid,
            'medium' => $room->type === 'meeting' ? 'meeting' : ($room->startsWithVideo() ? 'video' : 'audio'),
            'status' => $status,
            'direction' => (int) $room->owner_id === (int) $viewer->id ? 'outgoing' : 'incoming',
            'started_at' => $room->created_at?->toIso8601String(),
            'connected_at' => $room->connected_at?->toIso8601String(),
            'ended_at' => $room->ended_at?->toIso8601String(),
            'duration_seconds' => $estimated ? null : $room->durationInSeconds(),
            'duration_is_estimated' => $estimated,
            'can_join' => $canUseCalls && $room->isActive() && $room->mayJoin($viewer),
            'can_open_history' => $canUseCalls && $canOpenHistory,
        ];
    }
}
