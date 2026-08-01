<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['chat_id', 'user_id', 'reply_to_message_id', 'body', 'message_type', 'view_once', 'voice_duration_seconds', 'voice_waveform'];

    /**
     * Chat-Inhalte werden mit dem App-Key verschlüsselt gespeichert.
     */
    protected $casts = [
        'body' => 'encrypted',
        'view_once' => 'boolean',
        'voice_duration_seconds' => 'integer',
        'voice_waveform' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id')->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatMessageReaction::class);
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
        return $this->message_type === 'voice' || $this->voiceFile() !== null;
    }

    public function voiceFile(): ?File
    {
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        return $files->first(function (File $file): bool {
            if ($file->type === 'voice') {
                return true;
            }

            $name = strtolower((string) $file->name);
            $isRecorderFile = preg_match('/^sprachnachricht(?:[-_.])/', $name) === 1;

            if ($isRecorderFile) {
                return true;
            }

            if ($this->message_type !== 'voice') {
                return false;
            }

            $mime = strtolower((string) $file->mime_type);
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            return str_starts_with($mime, 'audio/')
                || in_array($extension, ['webm', 'ogg', 'm4a', 'mp3', 'wav', 'aac'], true);
        });
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

    public function hasActiveVoicePlaybackFor(User $user): bool
    {
        return Cache::has(static::voicePlaybackCacheKey($this->id, $user->id));
    }

    public function replyPreviewText(): string
    {
        if ($this->trashed()) {
            return __('app.chat_original_message_unavailable');
        }

        if ($this->view_once) {
            return __('app.chat_view_once_voice_message');
        }

        if ($this->isVoice()) {
            return __('app.voice_message');
        }

        $body = trim(rescue(fn (): string => (string) $this->body, '', report: false));

        if ($body !== '') {
            return Str::limit($body, 110);
        }

        $file = $this->relationLoaded('files') ? $this->files->first() : $this->files()->first();

        return $file?->name
            ? __('app.chat_attachment_named', ['name' => Str::limit((string) $file->name, 80)])
            : __('app.chat_attachment');
    }
}
