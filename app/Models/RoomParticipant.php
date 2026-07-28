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

    /**
     * Verbindungszustand der Teilnahme (invited|joined|left|disconnected).
     *
     * ACHTUNG: 'connection' heisst auch die geschuetzte Eloquent-Eigenschaft
     * fuer den Namen der DB-Verbindung. Jede Klasse, die selbst von Model
     * erbt (z. B. Room), darf laut PHP-Sichtbarkeitsregeln auf diese geerbte
     * Eigenschaft zugreifen – dort liefert $participant->connection deshalb
     * 'mysql'/'sqlite' statt des Spaltenwerts und __get() wird nie erreicht.
     * Deswegen laeuft jeder Zugriff hier ueber getAttribute().
     */
    public function connectionState(): ?string
    {
        return $this->getAttribute('connection');
    }

    /** Von der Moderation aus dem Anruf entfernt. */
    public function isRemoved(): bool
    {
        return $this->connectionState() === 'disconnected';
    }

    /** Aktuell mit der SFU verbunden (setzt der participant_joined-Webhook). */
    public function isConnected(): bool
    {
        return $this->connectionState() === 'joined';
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
