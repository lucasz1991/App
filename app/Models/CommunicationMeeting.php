<?php

namespace App\Models;

use App\Casts\UtcImmutableDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommunicationMeeting extends Model
{
    protected $fillable = ['title', 'owner_id', 'team_id', 'chat_id', 'starts_at', 'ends_at', 'timezone', 'video', 'status'];

    protected $casts = ['starts_at' => UtcImmutableDateTime::class, 'ends_at' => UtcImmutableDateTime::class, 'video' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $meeting) => $meeting->uuid ??= (string) Str::uuid());
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CommunicationMeetingParticipant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Room::class, 'communication_meeting_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($visible) => $visible->where('owner_id', $user->id)
            ->orWhereHas('participants', fn ($members) => $members->where('user_id', $user->id)));
    }

    public function involves(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id
            || $this->participants()->where('user_id', $user->id)->exists();
    }
}
