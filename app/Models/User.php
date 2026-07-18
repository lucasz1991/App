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
}
