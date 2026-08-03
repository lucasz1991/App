<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatLiveLocation extends Model
{
    /** @var list<int> */
    public const ALLOWED_DURATIONS = [15, 30, 45, 60, 90];

    public const STOP_USER = 'user';

    public const STOP_REPLACED = 'replaced';

    public const STOP_CHAT_LEFT = 'chat_left';

    public const STOP_CALL_LEFT = 'call_left';

    public const STOP_CALL_ENDED = 'call_ended';

    public const STOP_CALL_REMOVED = 'call_removed';

    public const STOP_LOGOUT = 'logout';

    protected $fillable = [
        'uuid',
        'chat_message_id',
        'user_id',
        'duration_minutes',
        'latest_position',
        'last_position_at',
        'expires_at',
        'stopped_at',
        'stop_reason',
        'version',
    ];

    protected $hidden = [
        'latest_position',
    ];

    protected $casts = [
        'latest_position' => 'encrypted:array',
        'duration_minutes' => 'integer',
        'last_position_at' => 'datetime',
        'expires_at' => 'datetime',
        'stopped_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatLiveLocation $location): void {
            if (! $location->uuid) {
                $location->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    /** @return array<string, float|null> */
    public function position(): array
    {
        $position = is_array($this->latest_position) ? $this->latest_position : [];

        return [
            'latitude' => isset($position['latitude']) ? (float) $position['latitude'] : null,
            'longitude' => isset($position['longitude']) ? (float) $position['longitude'] : null,
            'accuracy' => isset($position['accuracy']) ? (float) $position['accuracy'] : null,
            'altitude' => isset($position['altitude']) ? (float) $position['altitude'] : null,
            'altitude_accuracy' => isset($position['altitude_accuracy']) ? (float) $position['altitude_accuracy'] : null,
            'heading' => isset($position['heading']) ? (float) $position['heading'] : null,
            'speed' => isset($position['speed']) ? (float) $position['speed'] : null,
        ];
    }

    public function status(): string
    {
        if ($this->stopped_at !== null) {
            return 'stopped';
        }

        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function isActive(): bool
    {
        return $this->status() === 'active';
    }

    public function remainingSeconds(): int
    {
        if (! $this->isActive()) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    public function stop(string $reason, ?Carbon $at = null): bool
    {
        if ($this->stopped_at !== null) {
            return false;
        }

        $this->forceFill([
            'stopped_at' => $at ?? now(),
            'stop_reason' => $reason,
            'version' => max(1, (int) $this->version) + 1,
        ])->save();

        return true;
    }
}
