<?php

namespace App\Models;

use App\Services\Calls\MeetingPlanningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'uuid', 'name', 'type', 'status', 'owner_id', 'chat_id', 'team_id',
        'call_chat_id', 'scheduled_at', 'started_at', 'connected_at', 'ended_at',
        'ended_reason', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'connected_at' => 'datetime',
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

    public function callChat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'call_chat_id');
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

    public function events(): HasMany
    {
        return $this->hasMany(RoomEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function recording(): HasOne
    {
        return $this->hasOne(RoomRecording::class);
    }

    /** Fuer Laravels implizite, gescopedte {recording:uuid}-Route. */
    public function recordings(): HasMany
    {
        return $this->hasMany(RoomRecording::class);
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
        $connectedAt = $this->connected_at;

        if (! $connectedAt) {
            return null;
        }

        return (int) $connectedAt->diffInSeconds($this->ended_at ?? now(), false);
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
        if (! $this->canAccessMeeting($user)) {
            return false;
        }
        $meetingId = $this->communicationMeetingId();
        if ($meetingId && CommunicationMeeting::find($meetingId)?->status !== 'scheduled') {
            return false;
        }
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
        $latest = $this->relationLoaded('invitations')
            ? $this->invitations
                ->where('invitee_id', $user->id)
                ->sortByDesc('id')
                ->first()
            : $this->invitations()
                ->where('invitee_id', $user->id)
                ->latest('id')
                ->first();

        // Eine Einladung wird erst durch die ausdrueckliche Annahme zum
        // Medienrecht. Insbesondere darf ein direkter POST auf den Token-
        // Endpunkt weder ein noch laufendes Klingeln noch einen verspaeteten
        // Queue-Timeout umgehen.
        if ($latest) {
            return $latest->status === 'accepted';
        }

        // Offene Meetings besitzen bewusst keine Einladung. Ebenso duerfen
        // historische/tatsaechlich bereits beigetretene Teilnehmer erneut
        // verbinden, auch wenn alte Daten noch keine Invitation-Zeile hatten.
        return $this->type === 'meeting'
            || $participant->joined_at !== null
            || in_array($participant->connectionState(), ['joined', 'left'], true);
    }

    public function canAccessMeeting(User $user): bool
    {
        $meetingId = $this->communicationMeetingId();
        $legacyId = data_get($this->settings, 'communication_meeting_id');
        if ($legacyId && $this->getAttribute('communication_meeting_id')
            && (int) $legacyId !== (int) $this->getAttribute('communication_meeting_id')) {
            return false;
        }

        return ! $meetingId || (app(MeetingPlanningService::class)->ready()
            && CommunicationMeeting::find($meetingId)?->involves($user));
    }

    public function communicationMeetingId(): ?int
    {
        $id = $this->getAttribute('communication_meeting_id') ?? data_get($this->settings, 'communication_meeting_id');

        return $id ? (int) $id : null;
    }

    /** Reading an entry point does not itself accept the invitation. */
    public function mayEnter(User $user): bool
    {
        if (! $this->isActive() || ! $user->status || ! $this->canAccessMeeting($user)) {
            return false;
        }
        if (data_get($this->settings, 'provisioning')) {
            return false;
        }
        $participant = $this->participantFor($user);
        if (! $participant || $participant->isRemoved()) {
            return false;
        }
        $meetingId = $this->communicationMeetingId();
        if ($meetingId && CommunicationMeeting::find($meetingId)?->status !== 'scheduled') {
            return false;
        }
        if ($this->mayJoin($user)) {
            return true;
        }
        $latest = $this->invitations()->where('invitee_id', $user->id)->latest('id')->first();

        return $latest && $latest->isPending() && ! $latest->isExpired();
    }

    public function scopeAccessibleMeetings(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            if (app(MeetingPlanningService::class)->ready()) {
                $visible->where(fn ($open) => $open->whereNull('communication_meeting_id')->whereNull('settings->communication_meeting_id'))
                    ->orWhere(function ($private) use ($user): void {
                        $private->whereIn('communication_meeting_id', CommunicationMeeting::query()->visibleTo($user)->select('id'))
                            ->where(fn ($consistent) => $consistent->whereNull('settings->communication_meeting_id')
                                ->orWhereColumn('settings->communication_meeting_id', 'communication_meeting_id'));
                    })->orWhere(function ($legacy) use ($user): void {
                        $legacy->whereNull('communication_meeting_id')
                            ->whereIn('settings->communication_meeting_id', CommunicationMeeting::query()->visibleTo($user)->select('id'));
                    });
            } else {
                $visible->whereNull('settings->communication_meeting_id');
            }
        });
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
