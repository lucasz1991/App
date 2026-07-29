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

    /** Wurde der Anruf als Videoanruf gestartet? (Kamera beim Beitritt an) */
    public function startsWithVideo(): bool
    {
        return (bool) ($this->settings['video'] ?? true);
    }

    /** Gespraechsdauer in Sekunden, sofern der Anruf zustande kam. */
    public function durationInSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->ended_at ?? now(), false);
    }

    /** Dauer als "m:ss" bzw. "h:mm:ss" – null, wenn nie verbunden. */
    public function durationForHumans(): ?string
    {
        $seconds = $this->durationInSeconds();

        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
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

    /**
     * Darf der Nutzer diesem Raum (wieder) beitreten?
     *
     * Die Teilnehmerzeile entsteht bereits beim Klingeln und ist deshalb allein
     * kein Beitrittsrecht: Wer abgelehnt hat, wen der Ring-Timeout verpasst hat
     * oder wen die Moderation entfernt hat, bleibt draussen, bis er erneut
     * eingeladen wird (CallInvitationService::inviteOne oeffnet die Zeile wieder).
     */
    public function mayJoin(User $user): bool
    {
        $participant = $this->participantFor($user);

        if (! $participant) {
            return false;
        }

        // Von der Moderation entfernt – ein bereits ausgestelltes Token darf
        // damit nicht mehr in ein neues Beitritts-Token verlaengert werden.
        // isRemoved() statt ->connection: siehe RoomParticipant::connectionState().
        if ($participant->isRemoved()) {
            return false;
        }

        if ($participant->isModerator()) {
            return true;
        }

        // Nur die juengste Einladung zaehlt, damit eine erneute Einladung nach
        // einem Ablehnen wieder Zutritt gewaehrt.
        $latest = $this->invitations()
            ->where('invitee_id', $user->id)
            ->latest('id')
            ->first();

        return $latest === null
            || ! in_array($latest->status, ['declined', 'missed', 'expired'], true);
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

        // Admins bleiben global zustaendig – dasselbe Muster wie der
        // Gate::before-Bypass im AuthServiceProvider.
        if ($user->isAdmin()) {
            return true;
        }

        // Das Team-Recht 'calls.moderate' loest gegen das *eigene* currentTeam
        // auf, nie gegen diesen Raum. Ohne die Beteiligungspruefung macht ein
        // einziges gesetztes Flag den Nutzer zum Moderator jedes Anrufs der
        // gesamten Installation – auch fremder Chats, in denen er nichts zu
        // suchen hat.
        if (! $this->involves($user)) {
            return false;
        }

        return $user->hasRbacPermission('calls.moderate');
    }

    /** Gehoert der Nutzer ueberhaupt zu diesem Anruf (Teilnahme oder Chat)? */
    public function involves(User $user): bool
    {
        if ($this->participantFor($user) !== null) {
            return true;
        }

        if ($this->chat_id === null) {
            return false;
        }

        return $this->chat()
            ->whereHas('participants', fn ($query) => $query->whereKey($user->id))
            ->exists();
    }
}
