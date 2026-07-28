<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomParticipant extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'guest_name', 'role', 'connection',
        'livekit_identity', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isModerator(): bool
    {
        return in_array($this->role, ['host', 'moderator'], true);
    }

    public function canPublish(): bool
    {
        return in_array($this->role, ['host', 'moderator', 'speaker'], true);
    }
}
