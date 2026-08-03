<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Chat extends Model
{
    protected $fillable = ['type', 'name', 'created_by', 'photo_path'];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_user')
            ->withPivot('last_read_at', 'last_opened_at', 'joined_at', 'hidden_at', 'cleared_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    /** Aktuell laufender oder klingelnder Videoanruf in diesem Chat, falls vorhanden. */
    public function activeRoom(): HasOne
    {
        return $this->hasOne(Room::class)
            ->whereIn('status', ['pending', 'active'])
            ->latestOfMany();
    }

    /** The isolated call room that owns this conversation, if this is a call chat. */
    public function callRoom(): HasOne
    {
        return $this->hasOne(Room::class, 'call_chat_id');
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public function isCall(): bool
    {
        return $this->type === 'call';
    }

    public function participantFor(User|int $user): ?User
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('participants')) {
            return $this->participants->first(
                fn (User $participant): bool => (int) $participant->id === (int) $userId
            );
        }

        return $this->participants()
            ->where('users.id', $userId)
            ->first();
    }

    /**
     * Untere Sichtbarkeitsgrenze fuer einen Teilnehmer.
     *
     * Ein Leeren des Verlaufs verschiebt die Sichtbarkeitsgrenze, ohne die
     * gemeinsame Chat-Historie fuer andere Teilnehmer zu veraendern.
     */
    public function visibleSinceFor(User|int $user): ?Carbon
    {
        $participant = $this->participantFor($user);

        if (! $participant?->pivot) {
            return null;
        }

        $timestamps = collect([
            $participant->pivot->joined_at,
            $participant->pivot->cleared_at,
        ])->filter();

        if ($timestamps->isEmpty()) {
            return null;
        }

        return $timestamps
            ->map(fn ($timestamp): Carbon => Carbon::parse($timestamp))
            ->sortDesc()
            ->first();
    }

    public function canManageGroup(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->isGroup()
            && (int) $this->created_by === (int) $userId
            && $this->participantFor($userId) !== null;
    }

    /** Anzeigename aus Sicht eines Betrachters (Direktchat = Gegenueber). */
    public function displayNameFor(User $viewer): string
    {
        if ($this->isGroup() || $this->isCall()) {
            return (string) ($this->name ?: 'Gruppe');
        }

        $other = $this->participants->firstWhere('id', '!=', $viewer->id);

        return $other?->name ?? 'Chat';
    }

    /** Avatar-URL aus Sicht des Betrachters (Direktchat = Gegenueber, Gruppe = Gruppenbild). */
    public function avatarUrlFor(User $viewer): ?string
    {
        if ($this->isGroup() || $this->isCall()) {
            return $this->photo_path
                ? Storage::disk('public')->url($this->photo_path)
                : null;
        }

        return $this->participants->firstWhere('id', '!=', $viewer->id)?->profile_photo_url;
    }

    /** Ungelesene Nachrichten fuer einen Teilnehmer. */
    public function unreadCountFor(User $user): int
    {
        $pivot = $this->participants->firstWhere('id', $user->id)?->pivot;

        if (! $pivot || $pivot->hidden_at) {
            return 0;
        }

        $lastRead = $pivot?->last_read_at;
        $visibleSince = $this->visibleSinceFor($user);

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($visibleSince, fn ($query) => $query->where('created_at', '>=', $visibleSince))
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
    }

    /** Sind alle anderen Teilnehmer mit ihrem Lesestand an der Nachricht vorbei? */
    public function messageReadByAllRecipients(ChatMessage $message, User $sender): bool
    {
        $recipients = $this->participants
            ->where('id', '!=', $sender->id)
            ->filter(function (User $recipient) use ($message): bool {
                $joinedAt = $recipient->pivot?->joined_at;

                return ! $joinedAt || Carbon::parse($joinedAt)->lessThanOrEqualTo($message->created_at);
            });

        return $recipients->isNotEmpty() && $recipients->every(function (User $recipient) use ($message): bool {
            $lastReadAt = $recipient->pivot?->last_read_at;

            return $lastReadAt && Carbon::parse($lastReadAt)->greaterThanOrEqualTo($message->created_at);
        });
    }

    /** Existierenden Direktchat zwischen zwei Nutzern finden oder anlegen. */
    public static function directBetween(User $a, User $b): self
    {
        $existing = static::query()
            ->where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $b->id))
            ->first();

        if ($existing) {
            $existing->load('participants');

            foreach ([$a, $b] as $user) {
                $participant = $existing->participantFor($user);
                $joinedAt = $participant?->pivot?->joined_at
                    ?: $participant?->pivot?->created_at
                    ?: now();

                $existing->participants()->updateExistingPivot($user->id, [
                    'joined_at' => $joinedAt,
                    'hidden_at' => null,
                ]);
            }

            $existing->unsetRelation('participants');

            return $existing;
        }

        $chat = static::create(['type' => 'direct', 'created_by' => $a->id]);
        $joinedAt = now();

        // Wichtig: identische Pivot-Spalten je Zeile (sonst schlaegt der Bulk-Insert fehl)
        $chat->participants()->attach([
            $a->id => [
                'last_read_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
            $b->id => [
                'last_read_at' => null,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
        ]);

        return $chat;
    }
}
