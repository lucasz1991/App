<?php

namespace App\Models;

use App\Events\MessageReceived;
use App\Notifications\RailtimeWebPushNotification;
use App\Support\Push\PushCategory;
use App\Support\Push\PushDelivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\Models\Activity;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasPushSubscriptions;
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

    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            $user->pushSubscriptions()->delete();
        });
    }

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
        return $this->dashboardTeam()?->name === 'Administratoren';
    }

    public function canViewManagementDashboard(): bool
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
        return $this->morphMany(Activity::class, 'causer');
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

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(EmployeeDocumentRequirement::class);
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

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function wantsWebPush(PushCategory|string $category): bool
    {
        $categoryValue = $category instanceof PushCategory ? $category->value : $category;

        return config('webpush.enabled')
            && $this->notificationPreferences()
                ->where('category', $categoryValue)
                ->where('web_push_enabled', true)
                ->exists();
    }

    public function enableDefaultPushPreferences(): void
    {
        foreach (PushCategory::cases() as $category) {
            $this->notificationPreferences()->firstOrCreate(
                ['category' => $category->value],
                ['web_push_enabled' => true],
            );
        }
    }

    public function routeNotificationForWebPush(?object $notification = null): Collection
    {
        $subscriptions = $this->pushSubscriptions()->whereNull('revoked_at');

        if ($notification instanceof RailtimeWebPushNotification
            && $notification->targetSubscriptionId !== null) {
            $subscriptions->whereKey($notification->targetSubscriptionId);
        }

        return $subscriptions->get();
    }

    public function deletePushSubscription(string $endpoint): void
    {
        $this->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();
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
    public function receiveMessage(
        string $subject,
        string $message,
        ?int $fromUserId = null,
        $files = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null
    ): Message {
        $receivedMessage = Message::create([
            'subject' => $subject,
            'message' => $message,
            'action_url' => $actionUrl,
            'action_label' => $actionLabel,
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
            event(new MessageReceived($message));
        } catch (\Throwable $e) {
            Log::notice('Broadcast der Nachricht fehlgeschlagen', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        app(PushDelivery::class)->messageReceived($message);
    }

    /** Chats, an denen der Benutzer teilnimmt. */
    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_user')
            ->withPivot('last_read_at', 'last_opened_at', 'joined_at', 'hidden_at', 'cleared_at')
            ->wherePivotNull('hidden_at')
            ->withTimestamps();
    }

    /** Teilnahmen an Videoanrufen (aktuell und historisch). */
    public function roomParticipations(): HasMany
    {
        return $this->hasMany(RoomParticipant::class);
    }

    /** Der Videoanruf, an dem der Benutzer aktuell aktiv teilnimmt (falls vorhanden). */
    public function activeCall(): ?Room
    {
        return $this->roomParticipations()
            ->where('connection', 'joined')
            ->whereHas('room', fn ($q) => $q->whereIn('status', ['pending', 'active']))
            ->with('room')
            ->latest('joined_at')
            ->first()
            ?->room;
    }

    /**
     * Persoenliche Ton-Zuordnung. Enthaelt nur die bewussten Abweichungen vom
     * systemweiten Standard (siehe App\Support\Sound\SoundLibrary).
     */
    public function soundPreferences(): HasMany
    {
        return $this->hasMany(UserSoundPreference::class);
    }

    /** Nur die globale Admin-Rolle nutzt Layout und URL /administrator. */
    public function usesAdminLayout(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Alle dem Benutzer bereitgestellten Dateien, gruppiert nach Herkunft:
     *  - 'personal': persoenlicher Pool (vom Admin im Profil hinzugefuegt)
     *  - 'company':  firmenweite Freigaben für die Teams des Benutzers
     *  - 'teams':    Standard-Downloads der Teams des Benutzers
     * Beruecksichtigt nur sichtbare (Zeitfenster + Team) und nicht abgelaufene
     * Dateien; neueste zuerst.
     *
     * @return array{personal: \Illuminate\Support\Collection, company: \Illuminate\Support\Collection, teams: array<int, array{team: Team, files: \Illuminate\Support\Collection}>}
     */
    public function availableFilesGrouped(): array
    {
        $me = $this;
        $visible = fn (File $file) => $me->canAccessFile($file, 'view');

        // Persoenlicher Pool
        $personal = ($this->filePool?->files()->latest()->get() ?? collect())
            ->filter($visible)->values();

        // Firmen-Freigaben: ausschließlich teambezogene Sichtbarkeit/Rechte.
        $company = FilePool::company()->files()->latest()->get()
            ->filter($visible)
            ->values();

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
     * Zentraler Zugriffstest für Dateien aus Datei-Pools.
     *
     * Firmen-Dateien werden über Team-Sichtbarkeit und – sofern sie in einem
     * Ordner liegen – über dessen Team-Rechtematrix autorisiert. Persönliche
     * Pools bleiben beim Zielbenutzer, Team-Pools bei ihren Mitgliedern.
     */
    public function canAccessFile(File $file, string $action = 'view'): bool
    {
        if (! in_array($action, array_keys(FileFolder::permissionActions()), true)) {
            return false;
        }

        if (! $file->isPubliclyVisible($this) || $file->isExpired()) {
            return false;
        }

        if ($file->folder_id) {
            $folder = $file->folder;

            if (! $folder
                || ! $folder->isPubliclyVisible($this)
                || ! $folder->allowsForUser($this, $action)) {
                return false;
            }
        }

        if ($file->fileable_type !== (new FilePool)->getMorphClass()) {
            return false;
        }

        $pool = FilePool::find($file->fileable_id);

        if (! $pool) {
            return false;
        }

        if ($pool->filepoolable_type === 'company') {
            // Wurzeldateien des Firmen-Pools müssen ausdrücklich mindestens
            // einem Team zugeordnet sein. So werden alte Rollenfreigaben ohne
            // Teamzuordnung beim Systemwechsel nicht versehentlich öffentlich.
            if (! $file->folder_id && empty($file->visible_teams)) {
                return false;
            }

            return true;
        }

        if ($pool->filepoolable_type === static::class) {
            return (int) $pool->filepoolable_id === (int) $this->id;
        }

        if ($pool->filepoolable_type === Team::class) {
            $team = Team::find($pool->filepoolable_id);

            return $team && $this->belongsToTeam($team);
        }

        return false;
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
