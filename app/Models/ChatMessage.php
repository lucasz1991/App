<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'user_id', 'body', 'message_type', 'view_once'];

    /**
     * Chat-Inhalte werden mit dem App-Key verschlüsselt gespeichert.
     */
    protected $casts = [
        'body' => 'encrypted',
        'view_once' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function views(): HasMany
    {
        return $this->hasMany(ChatMessageView::class);
    }

    public function isVoice(): bool
    {
        return $this->message_type === 'voice';
    }

    public function hasBeenViewedBy(User $user): bool
    {
        if ($this->relationLoaded('views')) {
            return $this->views->contains('user_id', $user->id);
        }

        return $this->views()->where('user_id', $user->id)->exists();
    }

    public static function voicePlaybackCacheKey(int $messageId, int $userId): string
    {
        return "chat-voice-playback:{$messageId}:{$userId}";
    }
}
