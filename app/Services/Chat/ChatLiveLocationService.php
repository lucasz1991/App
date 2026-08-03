<?php

namespace App\Services\Chat;

use App\Events\ChatLiveLocationChanged;
use App\Events\ChatMessageReceived;
use App\Events\ChatMessageSent;
use App\Models\Chat;
use App\Models\ChatLiveLocation;
use App\Models\ChatMessage;
use App\Models\Room;
use App\Models\User;
use App\Support\Push\PushDelivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatLiveLocationService
{
    public function __construct(
        protected ChatMessageInteractionService $interactions,
        protected PushDelivery $push,
    ) {}

    /** @return Collection<int, ChatLiveLocation> */
    public function activeFor(User $user): Collection
    {
        return ChatLiveLocation::query()
            ->where('user_id', $user->id)
            ->whereNull('stopped_at')
            ->where('expires_at', '>', now())
            ->whereHas('message', fn ($query) => $query->whereNull('deleted_at'))
            ->with([
                'message.chat.participants',
                'message.chat.callRoom.participants',
            ])
            ->orderBy('expires_at')
            ->get()
            ->filter(function (ChatLiveLocation $location) use ($user): bool {
                $chat = $location->message?->chat;

                return $chat !== null
                    && $this->hasVisibleMembership($chat, $user)
                    && $this->canWrite($chat, $user);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function start(Chat $chat, User $user, array $data): ChatLiveLocation
    {
        /** @var Collection<int, ChatLiveLocation> $replaced */
        $replaced = new Collection;

        [$location, $message, $callChat] = DB::transaction(function () use (
            $chat,
            $user,
            $data,
            &$replaced,
        ): array {
            $lockedChat = Chat::query()
                ->whereKey($chat->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedChat->load(['participants', 'callRoom.participants']);
            $this->assertCanWrite($lockedChat, $user);

            $duration = (int) $data['duration_minutes'];
            abort_unless(in_array($duration, ChatLiveLocation::ALLOWED_DURATIONS, true), 422);

            $replyTarget = $this->interactions->replyTarget(
                $lockedChat,
                $user,
                isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null,
            );
            $startedAt = now();

            $replaced = ChatLiveLocation::query()
                ->where('user_id', $user->id)
                ->whereNull('stopped_at')
                ->where('expires_at', '>', $startedAt)
                ->whereHas('message', fn ($query) => $query->where('chat_id', $lockedChat->id))
                ->lockForUpdate()
                ->with('message')
                ->get();

            $replaced->each(
                fn (ChatLiveLocation $active): bool => $active->stop(
                    ChatLiveLocation::STOP_REPLACED,
                    $startedAt,
                )
            );

            $message = ChatMessage::create([
                'chat_id' => $lockedChat->id,
                'user_id' => $user->id,
                'reply_to_message_id' => $replyTarget?->id,
                'body' => '',
                'message_type' => 'live_location',
                'view_once' => false,
            ]);

            $location = $message->liveLocation()->create([
                'user_id' => $user->id,
                'duration_minutes' => $duration,
                'latest_position' => $this->normalizePosition($data),
                'last_position_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes($duration),
                'version' => 1,
            ]);

            if (! $lockedChat->isCall()) {
                DB::table('chat_user')
                    ->where('chat_id', $lockedChat->id)
                    ->whereNotNull('hidden_at')
                    ->update([
                        'hidden_at' => null,
                        'updated_at' => $startedAt,
                    ]);
            }

            $lockedChat->touch();
            $pivotUpdate = ['last_read_at' => $startedAt];
            if ($lockedChat->isCall()) {
                $pivotUpdate['last_opened_at'] = $startedAt;
            }
            $lockedChat->participants()->updateExistingPivot($user->id, $pivotUpdate);

            $message->setRelation('chat', $lockedChat);
            $location->setRelation('message', $message);

            return [$location, $message, $lockedChat->isCall()];
        });

        $replaced->each(fn (ChatLiveLocation $active) => $this->publishChange($active));
        $this->publishMessageCreated($message, $callChat);
        $this->publishChange($location);

        return $location->refresh()->load('message');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ChatLiveLocation $location, User $user, array $data): ChatLiveLocation
    {
        [$location, $updated, $stopped] = DB::transaction(function () use ($location, $user, $data): array {
            $locked = ChatLiveLocation::query()
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->with([
                    'message.chat.participants',
                    'message.chat.callRoom.participants',
                ])
                ->firstOrFail();
            $message = $locked->message;
            $chat = $message?->chat;

            abort_unless($message && $chat, 404);
            $this->assertOwner($locked, $message, $user);
            $this->assertVisibleMembership($chat, $user);

            if (! $locked->isActive()) {
                return [$locked, false, false];
            }

            if (! $this->canWrite($chat, $user)) {
                $reason = $this->callStopReason($chat, $user);

                if ($reason === null) {
                    abort(403);
                }

                $stopped = $locked->stop($reason);

                return [$locked, false, $stopped];
            }

            $locked->forceFill([
                'latest_position' => $this->normalizePosition($data),
                'last_position_at' => now(),
                'version' => max(1, (int) $locked->version) + 1,
            ])->save();

            return [$locked, true, false];
        });

        if ($updated || $stopped) {
            $this->publishChange($location);
        }

        abort_unless($updated, 410, __('app.chat_live_location_inactive'));

        return $location->refresh()->load('message');
    }

    public function stop(
        ChatLiveLocation $location,
        User $user,
        string $reason = ChatLiveLocation::STOP_USER,
    ): ChatLiveLocation {
        [$location, $changed] = DB::transaction(function () use ($location, $user, $reason): array {
            $locked = ChatLiveLocation::query()
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->with('message.chat.participants')
                ->firstOrFail();
            $message = $locked->message;
            $chat = $message?->chat;

            abort_unless($message && $chat, 404);
            $this->assertOwner($locked, $message, $user);
            $this->assertVisibleMembership($chat, $user);

            $changed = $locked->isActive() && $locked->stop($reason);

            return [$locked, $changed];
        });

        if ($changed) {
            $this->publishChange($location);
        }

        return $location->refresh()->load('message');
    }

    public function stopForChat(
        Chat|int $chat,
        string $reason = ChatLiveLocation::STOP_CALL_ENDED,
    ): int {
        $chatId = $chat instanceof Chat ? (int) $chat->id : $chat;
        $changed = DB::transaction(function () use ($chatId, $reason): Collection {
            $locations = ChatLiveLocation::query()
                ->whereNull('stopped_at')
                ->where('expires_at', '>', now())
                ->whereHas('message', fn ($query) => $query->where('chat_id', $chatId))
                ->lockForUpdate()
                ->with('message')
                ->get();

            return $locations
                ->filter(fn (ChatLiveLocation $location): bool => $location->stop($reason))
                ->values();
        });

        $changed->each(fn (ChatLiveLocation $location) => $this->publishChange($location));

        return $changed->count();
    }

    public function stopForUserInChat(
        Chat|int $chat,
        User|int $user,
        string $reason,
    ): int {
        $chatId = $chat instanceof Chat ? (int) $chat->id : $chat;
        $userId = $user instanceof User ? (int) $user->id : $user;
        $changed = DB::transaction(function () use ($chatId, $userId, $reason): Collection {
            $locations = ChatLiveLocation::query()
                ->where('user_id', $userId)
                ->whereNull('stopped_at')
                ->where('expires_at', '>', now())
                ->whereHas('message', fn ($query) => $query->where('chat_id', $chatId))
                ->lockForUpdate()
                ->with('message')
                ->get();

            return $locations
                ->filter(fn (ChatLiveLocation $location): bool => $location->stop($reason))
                ->values();
        });

        $changed->each(fn (ChatLiveLocation $location) => $this->publishChange($location));

        return $changed->count();
    }

    public function stopForUser(
        User|int $user,
        string $reason = ChatLiveLocation::STOP_LOGOUT,
    ): int {
        $userId = $user instanceof User ? (int) $user->id : $user;
        $changed = DB::transaction(function () use ($userId, $reason): Collection {
            $locations = ChatLiveLocation::query()
                ->where('user_id', $userId)
                ->whereNull('stopped_at')
                ->where('expires_at', '>', now())
                ->whereHas('message', fn ($query) => $query->whereNull('deleted_at'))
                ->lockForUpdate()
                ->with('message')
                ->get();

            return $locations
                ->filter(fn (ChatLiveLocation $location): bool => $location->stop($reason))
                ->values();
        });

        $changed->each(fn (ChatLiveLocation $location) => $this->publishChange($location));

        return $changed->count();
    }

    protected function assertCanWrite(Chat $chat, User $user): void
    {
        $this->assertVisibleMembership($chat, $user);
        abort_unless($this->canWrite($chat, $user), 403, __('app.calls_chat_read_only'));
    }

    protected function assertVisibleMembership(Chat $chat, User $user): void
    {
        abort_unless($this->hasVisibleMembership($chat, $user), 403);
    }

    protected function hasVisibleMembership(Chat $chat, User $user): bool
    {
        if (! $chat->relationLoaded('participants')) {
            $chat->load('participants');
        }

        $participant = $chat->participantFor($user);

        return $participant !== null && $participant->pivot?->hidden_at === null;
    }

    protected function canWrite(Chat $chat, User $user): bool
    {
        if (! in_array($chat->type, ['direct', 'group', 'call'], true)) {
            return false;
        }

        if (! $chat->isCall()) {
            return true;
        }

        $room = $this->callRoom($chat);
        $participant = $room?->participantFor($user);

        return $room !== null
            && $room->isActive()
            && $participant !== null
            && ! $participant->isRemoved();
    }

    protected function callStopReason(Chat $chat, User $user): ?string
    {
        if (! $chat->isCall()) {
            return null;
        }

        $room = $this->callRoom($chat);
        if (! $room || ! $room->isActive()) {
            return ChatLiveLocation::STOP_CALL_ENDED;
        }

        $participant = $room->participantFor($user);

        return $participant?->isRemoved()
            ? ChatLiveLocation::STOP_CALL_REMOVED
            : null;
    }

    protected function callRoom(Chat $chat): ?Room
    {
        if ($chat->relationLoaded('callRoom')) {
            return $chat->callRoom;
        }

        return $chat->callRoom()->with('participants')->first();
    }

    protected function assertOwner(
        ChatLiveLocation $location,
        ChatMessage $message,
        User $user,
    ): void {
        abort_unless(
            (int) $location->user_id === (int) $user->id
                && (int) $message->user_id === (int) $user->id,
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, float|null>
     */
    protected function normalizePosition(array $data): array
    {
        return [
            'latitude' => round((float) $data['latitude'], 6),
            'longitude' => round((float) $data['longitude'], 6),
            'accuracy' => round((float) $data['accuracy'], 1),
            'altitude' => isset($data['altitude']) ? round((float) $data['altitude'], 1) : null,
            'altitude_accuracy' => isset($data['altitude_accuracy'])
                ? round((float) $data['altitude_accuracy'], 1)
                : null,
            'heading' => isset($data['heading']) ? round((float) $data['heading'], 1) : null,
            'speed' => isset($data['speed']) ? round((float) $data['speed'], 2) : null,
        ];
    }

    protected function publishMessageCreated(ChatMessage $message, bool $callChat): void
    {
        $message->loadMissing(['chat', 'sender']);
        $this->publish(new ChatMessageSent($message));
        $this->publish(new ChatMessageReceived($message));

        if (! $callChat) {
            $this->push->chatMessageReceived($message);
        }
    }

    protected function publishChange(ChatLiveLocation $location): void
    {
        $location->loadMissing('message');
        $message = $location->message;

        if (! $message) {
            return;
        }

        $this->publish(new ChatLiveLocationChanged(
            chatId: (int) $message->chat_id,
            messageId: (int) $message->id,
            liveLocationId: (string) $location->uuid,
            version: (int) $location->version,
            status: $location->status(),
            expiresAt: $location->expires_at->toIso8601String(),
        ));
    }

    protected function publish(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $exception) {
            Log::notice('Chat-Live-Standort-Echtzeitereignis konnte nicht gesendet werden.', [
                'event' => $event::class,
                'error_class' => $exception::class,
            ]);
        }
    }
}
