<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'status' => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Das fuer die Dashboard-Auswahl massgebliche fachliche Team.
     * Bei mehreren alten/uneindeutigen Zuordnungen wird bewusst kein
     * Management-Team geraten, damit keine Systemdaten versehentlich
     * freigegeben werden.
     */
    public function dashboardTeam(): ?Team
    {
        $recognizedNames = [
            'Administratoren',
            'Administrator',
            'Administration',
            'Verwaltung',
            'Mitarbeiter',
            'Gäste',
            'Gaeste',
            'Gast',
        ];

        $currentTeam = $this->currentTeam;

        if ($currentTeam && ! $currentTeam->personal_team && in_array($currentTeam->name, $recognizedNames, true)) {
            return $currentTeam;
        }

        $recognizedTeams = $this->teams()
            ->where('personal_team', false)
            ->whereIn('teams.name', $recognizedNames)
            ->get();

        return $recognizedTeams->count() === 1 ? $recognizedTeams->first() : null;
    }

    /**
     * Liefert den fachlichen Dashboard-Typ unabhaengig von der technischen
     * globalen Rolle. Nur die globale Admin-Rolle verwendet /administrator.
     */
    public function dashboardAudience(): string
    {
        if ($this->isAdmin()) {
            return 'admin';
        }

        return match ($this->dashboardTeam()?->name) {
            'Administratoren', 'Administrator', 'Administration' => 'administration',
            'Verwaltung' => 'management',
            'Gäste', 'Gaeste', 'Gast' => 'guest',
            'Mitarbeiter' => 'employee',
            default => $this->role === 'staff' ? 'employee' : 'guest',
        };
    }

    public function canViewSystemDashboard(): bool
    {
        return in_array($this->dashboardAudience(), ['admin', 'administration', 'management'], true);
    }

    /**
     * Benutzer #1 ist der Super-Admin: erscheint nicht in der
     * Mitarbeiterliste und wird nicht im Activity-Log erfasst.
     */
    public function isSuperAdmin(): bool
    {
        return (int) $this->id === 1;
    }

    public function hasRbacPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $team = $this->currentTeam;
        $permissions = is_array($team?->rbac_permissions) ? $team->rbac_permissions : [];

        return (bool) ($permissions[$permission] ?? false);
    }

    public function isActive(): bool
    {
        return (bool) $this->status;
    }

    /**
     * Activities, die dieser User ausgeloest hat (Spatie Activitylog).
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(\Spatie\Activitylog\Models\Activity::class, 'causer');
    }

    /**
     * Ist der User "online"? (= hatte in den letzten $minutes eine Activity)
     */
    public function isOnline(int $minutes = 5): bool
    {
        return $this->activities()
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * Zeitpunkt der letzten Activity (oder null, wenn es keine gibt).
     */
    public function lastActivityAt(): ?Carbon
    {
        $timestamp = $this->activities()->latest('created_at')->value('created_at');

        return $timestamp ? Carbon::parse($timestamp) : null;
    }

    /**
     * Dateipool des Benutzers (Dateifreigaben durch die Verwaltung).
     */
    public function filePool(): MorphOne
    {
        return $this->morphOne(FilePool::class, 'filepoolable');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(UserNote::class);
    }

    /**
     * Empfangene Nachrichten (interner Posteingang).
     */
    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'to_user');
    }

    public function receivedUnreadMessages(): HasMany
    {
        return $this->receivedMessages()->where('status', 1);
    }

    /**
     * Sende eine Nachricht an einen anderen Benutzer.
     */
    public function sendMessage(int $toUserId, string $subject, string $message): void
    {
        $sent = Message::create([
            'subject' => $subject,
            'message' => $message,
            'from_user' => $this->id,
            'to_user' => $toUserId,
            'status' => '1',
        ]);

        $this->broadcastMessageReceived($sent);
    }

    /**
     * Interne Nachricht empfangen (wird u. a. vom ProcessMailJob genutzt).
     * $files: iterable von File-Modellen, deren Metadaten als Anhaenge
     * auf die Nachricht kopiert werden.
     */
    public function receiveMessage(string $subject, string $message, ?int $fromUserId = null, $files = null): Message
    {
        $receivedMessage = Message::create([
            'subject' => $subject,
            'message' => $message,
            'from_user' => $fromUserId ?? 1,
            'to_user' => $this->id,
            'status' => '1',
        ]);

        if ($files) {
            foreach ($files as $file) {
                $receivedMessage->files()->create([
                    'name' => $file->name,
                    'path' => $file->path,
                    'disk' => $file->disk ?? 'private',
                    'mime_type' => $file->mime_type,
                    'size' => $file->size,
                    'expires_at' => $file->expires_at ?? null,
                ]);
            }
        }

        $this->broadcastMessageReceived($receivedMessage);

        return $receivedMessage;
    }

    /**
     * Echtzeit-Benachrichtigung ueber Reverb ausloesen. Fehler (z. B. wenn
     * der Reverb-Server nicht laeuft) duerfen den Nachrichtenversand nie
     * blockieren — der Empfaenger sieht die Nachricht dann beim naechsten
     * Polling des Posteingangs.
     */
    protected function broadcastMessageReceived(Message $message): void
    {
        try {
            event(new \App\Events\MessageReceived($message));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::notice('Broadcast der Nachricht fehlgeschlagen', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Chats, an denen der Benutzer teilnimmt. */
    public function chats(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_user')
            ->withPivot('last_read_at', 'last_opened_at')
            ->withTimestamps();
    }

    /** Nur die globale Admin-Rolle nutzt Layout und URL /administrator. */
    public function usesAdminLayout(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Alle dem Benutzer bereitgestellten Dateien, gruppiert nach Herkunft:
     *  - 'personal': persoenlicher Pool (vom Admin im Profil hinzugefuegt)
     *  - 'company':  firmenweite Freigaben (per Rolle ODER per Team sichtbar)
     *  - 'teams':    Standard-Downloads der Teams des Benutzers
     * Beruecksichtigt nur sichtbare (Zeitfenster + Team) und nicht abgelaufene
     * Dateien; neueste zuerst.
     *
     * @return array{personal: \Illuminate\Support\Collection, company: \Illuminate\Support\Collection, teams: array<int, array{team: Team, files: \Illuminate\Support\Collection}>}
     */
    public function availableFilesGrouped(): array
    {
        $me = $this;
        $visible = fn (File $file) => $file->isPubliclyVisible($me) && ! $file->isExpired();

        // Persoenlicher Pool
        $personal = ($this->filePool?->files()->latest()->get() ?? collect())
            ->filter($visible)->values();

        // Firmen-Freigaben: rollen- ODER teambasiert freigegeben
        $company = FilePool::company()->files()->latest()->get()->filter(function (File $file) use ($me, $visible) {
            if (! $visible($file)) {
                return false;
            }

            if ($file->folder_id) {
                $folder = $file->folder;

                return $folder && $folder->allowsForRole($me->role, 'view');
            }

            // Wurzeldatei: per Rolle freigegeben ODER gezielt fuer ein Team sichtbar
            return $file->isSharedWithRole($me->role) || ! empty($file->visible_teams);
        })->values();

        // Team-Pools
        $teams = [];
        foreach ($this->teams()->where('personal_team', false)->orderBy('name')->get() as $team) {
            $pool = $team->filePool;
            $files = $pool
                ? $pool->files()->latest()->get()->filter($visible)->values()
                : collect();

            if ($files->isNotEmpty()) {
                $teams[] = ['team' => $team, 'files' => $files];
            }
        }

        return ['personal' => $personal, 'company' => $company, 'teams' => $teams];
    }

    /**
     * Flache Liste der IDs aller bereitgestellten Dateien (fuer Download-Checks).
     *
     * @return array<int, int>
     */
    public function availableFileIds(): array
    {
        $grouped = $this->availableFilesGrouped();

        $ids = $grouped['personal']->pluck('id')->merge($grouped['company']->pluck('id'));

        foreach ($grouped['teams'] as $entry) {
            $ids = $ids->merge($entry['files']->pluck('id'));
        }

        return $ids->unique()->values()->all();
    }
}
