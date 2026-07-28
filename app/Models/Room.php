<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'uuid', 'name', 'type', 'status', 'owner_id', 'chat_id', 'team_id',
        'scheduled_at', 'started_at', 'ended_at', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            if (! $room->uuid) {
                $room->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RoomParticipant::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RoomInvitation::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'active'], true);
    }

    /** Der LiveKit-Raumname entspricht der öffentlichen UUID. */
    public function livekitRoomName(): string
    {
        return $this->uuid;
    }

    public function participantFor(User|int $user): ?RoomParticipant
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('participants')) {
            return $this->participants->firstWhere('user_id', (int) $userId);
        }

        return $this->participants()->where('user_id', $userId)->first();
    }

    /** Darf der Nutzer diesen Raum moderieren (auflegen, stummschalten, entfernen)? */
    public function canModerate(User $user): bool
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return true;
        }

        $participant = $this->participantFor($user);

        if ($participant && in_array($participant->role, ['host', 'moderator'], true)) {
            return true;
        }

        return $user->hasRbacPermission('calls.moderate');
    }
}
