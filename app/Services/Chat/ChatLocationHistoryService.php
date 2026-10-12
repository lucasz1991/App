<?php

namespace App\Services\Chat;

use App\Models\ChatLiveLocation;
use App\Models\ChatLocationPoint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChatLocationHistoryService
{
    public const CONSENT_VERSION = 1;

    /** Called under the share row lock, after current ownership/membership checks. */
    public function record(ChatLiveLocation $share, array $data): void
    {
        if (! $share->profile_history_accepted_at || $share->profile_history_withdrawn_at || ! $share->isActive()
            || ! is_string($data['measured_at'] ?? null)) {
            return;
        }
        try {
            $measured = CarbonImmutable::parse($data['measured_at'])->utc();
        } catch (\Throwable) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        if ($measured->lt($now->subSeconds(30)) || $measured->gt($now->addSeconds(5))) {
            return;
        }
        foreach (['latitude' => 90, 'longitude' => 180, 'accuracy' => 50] as $field => $limit) {
            $value = $data[$field] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > $limit
                || ($field === 'accuracy' && (float) $value < 0)) {
                return;
            }
        }
        $latest = ChatLocationPoint::where('chat_live_location_id', $share->id)->orderByDesc('measured_at')->first();
        if ($latest && ($measured->lt($latest->measured_at->addMinute()) || $now->lt($latest->received_at->addMinute()))) {
            return;
        }
        ChatLocationPoint::create([
            'chat_live_location_id' => $share->id, 'user_id' => $share->user_id,
            'position' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']],
            'accuracy_m' => (float) $data['accuracy'], 'measured_at' => $measured, 'received_at' => $now,
        ]);
    }

    /** Withdrawal is possible after stopping, expiry or leaving the original chat. */
    public function withdraw(ChatLiveLocation $share, User $actor): ChatLiveLocation
    {
        return DB::transaction(function () use ($share, $actor): ChatLiveLocation {
            $locked = ChatLiveLocation::whereKey($share->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $actor->id, 403);
            if ($locked->profile_history_accepted_at && ! $locked->profile_history_withdrawn_at) {
                $locked->forceFill(['profile_history_withdrawn_at' => CarbonImmutable::now('UTC')])->save();
            }
            ChatLocationPoint::where('chat_live_location_id', $locked->id)->delete();

            return $locked;
        });
    }

    /** Bounded batches; expiry is also enforced in every read, independent of cron. */
    public function purge(): int
    {
        $deleted = 0;
        do {
            $ids = ChatLocationPoint::where('measured_at', '<=', CarbonImmutable::now('UTC')->subDays(7))
                ->orWhereIn('chat_live_location_id', function ($shares): void {
                    $shares->select('chat_live_locations.id')->from('chat_live_locations')
                        ->join('chat_messages', 'chat_messages.id', '=', 'chat_live_locations.chat_message_id')
                        ->whereNotNull('chat_live_locations.profile_history_withdrawn_at')
                        ->orWhereNotNull('chat_messages.deleted_at');
                })->orderBy('id')->limit(500)->pluck('id');
            $deleted += ChatLocationPoint::whereIn('id', $ids)->delete();
        } while ($ids->count() === 500);

        return $deleted;
    }
}
