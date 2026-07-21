<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chat extends Model
{
    protected $fillable = ['type', 'name', 'created_by'];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_user')
            ->withPivot('last_read_at')
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

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /** Anzeigename aus Sicht eines Betrachters (Direktchat = Gegenueber). */
    public function displayNameFor(User $viewer): string
    {
        if ($this->isGroup()) {
            return (string) ($this->name ?: 'Gruppe');
        }

        $other = $this->participants->firstWhere('id', '!=', $viewer->id);

        return $other?->name ?? 'Chat';
    }

    /** Avatar-URL aus Sicht des Betrachters (Direktchat = Gegenueber). */
    public function avatarUrlFor(User $viewer): ?string
    {
        if ($this->isGroup()) {
            return null;
        }

        return $this->participants->firstWhere('id', '!=', $viewer->id)?->profile_photo_url;
    }

    /** Ungelesene Nachrichten fuer einen Teilnehmer. */
    public function unreadCountFor(User $user): int
    {
        $pivot = $this->participants->firstWhere('id', $user->id)?->pivot;
        $lastRead = $pivot?->last_read_at;

        return $this->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
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
            return $existing;
        }

        $chat = static::create(['type' => 'direct', 'created_by' => $a->id]);
        // Wichtig: identische Pivot-Spalten je Zeile (sonst schlaegt der Bulk-Insert fehl)
        $chat->participants()->attach([
            $a->id => ['last_read_at' => now()],
            $b->id => ['last_read_at' => null],
        ]);

        return $chat;
    }
}
