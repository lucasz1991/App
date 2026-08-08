<?php

namespace App\Models;

use App\Services\Marketing\MarketingFileSourceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FileFolder extends Model
{
    protected $fillable = [
        'file_pool_id',
        'parent_id',
        'name',
        'permissions',
        'visible_from',
        'visible_until',
        'auto_delete',
        'visible_teams',
    ];

    protected $casts = [
        'permissions' => 'array',
        'visible_from' => 'date',
        'visible_until' => 'date',
        'auto_delete' => 'boolean',
        'visible_teams' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (FileFolder $folder): void {
            $marketingFiles = app(MarketingFileSourceService::class);

            if ($folder->isDirty(['parent_id', 'file_pool_id'])) {
                $marketingFiles->assertFolderCanMoveTo(
                    $folder,
                    $folder->parent_id ? (int) $folder->parent_id : null,
                    (int) $folder->file_pool_id,
                );
            }

            if ($folder->isDirty(['visible_from', 'visible_until', 'auto_delete'])) {
                $marketingFiles->handleFolderMutation($folder);
            }
        });

        static::deleting(function (FileFolder $folder): void {
            app(MarketingFileSourceService::class)->assertFolderCanBeDeleted($folder);
        });
    }

    /**
     * Aktionen, die je Team vergeben werden können.
     *
     * @return array<string, string>
     */
    public static function permissionActions(): array
    {
        return [
            'view' => 'Ansehen',
            'download' => 'Herunterladen',
            'delete' => 'Löschen',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(FilePool::class, 'file_pool_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id');
    }

    /**
     * Prüft, ob mindestens eines der Teams des Benutzers die Aktion in diesem
     * Ordner ausführen darf. Ohne gespeicherte Matrix ist der Ordner
     * uneingeschränkt. Admins dürfen immer alles.
     *
     * Die Matrix verwendet Team-IDs als Schlüssel:
     * [team_id => ['view' => bool, 'download' => bool, 'delete' => bool]]
     */
    public function allowsForUser(?User $user, string $action): bool
    {
        if (! array_key_exists($action, static::permissionActions())) {
            return false;
        }

        if ($user?->isAdmin()) {
            return true;
        }

        $permissions = $this->permissions;

        if (! is_array($permissions) || $permissions === []) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $user->allTeams()
            ->where('personal_team', false)
            ->contains(function (Team $team) use ($permissions, $action): bool {
                $teamPermissions = $permissions[(string) $team->id]
                    ?? $permissions[$team->id]
                    ?? null;

                return is_array($teamPermissions)
                    && (bool) ($teamPermissions[$action] ?? false);
            });
    }

    /* ------------------------------------------------------------------
     * Sichtbarkeit: Zeitfenster (visible_from/visible_until) + Team-Freigabe
     * (visible_teams). Greift zusätzlich zu den Team-Rechten; Admins sehen
     * immer alles.
     * ----------------------------------------------------------------*/

    /** Liegt "jetzt" innerhalb des Sichtbarkeitsfensters? */
    public function isWithinVisibilityWindow(): bool
    {
        $now = now();

        if ($this->visible_from && $now->lt($this->visible_from->copy()->startOfDay())) {
            return false;
        }

        if ($this->visible_until && $now->gt($this->visible_until->copy()->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Ist der Ordner fuer die Teams des Nutzers freigegeben? Ohne gesetzte
     * Teams (null/[]) ist er fuer alle sichtbar. Admins sehen immer alles.
     */
    public function isVisibleForTeams(?User $user): bool
    {
        $teamIds = collect($this->visible_teams ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        if ($teamIds->isEmpty()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        return $user->allTeams()->pluck('id')->intersect($teamIds)->isNotEmpty();
    }

    /** Gesamtsichtbarkeit fuer einen Nutzer (Zeitfenster UND Team-Freigabe). */
    public function isPubliclyVisible(?User $user): bool
    {
        return $this->isWithinVisibilityWindow() && $this->isVisibleForTeams($user);
    }

    /** Zum automatischen Loeschen faellig (auto_delete aktiv und abgelaufen)? */
    public function isExpiredForDeletion(): bool
    {
        return $this->auto_delete
            && $this->visible_until
            && now()->gt($this->visible_until->copy()->endOfDay());
    }

    /**
     * Breadcrumb-Pfad von der Wurzel bis zu diesem Ordner.
     *
     * @return array<int, self>
     */
    public function breadcrumb(): array
    {
        $path = [$this];
        $current = $this;

        while ($current->parent_id && ($current = $current->parent)) {
            array_unshift($path, $current);
        }

        return $path;
    }

    /**
     * Ordner samt Unterordnern und Dateien löschen (über Eloquent, damit
     * die Datei-Blobs auf der Disk mit entfernt werden).
     */
    public function deleteRecursive(bool $preflight = true): void
    {
        if ($preflight) {
            app(MarketingFileSourceService::class)->assertFolderCanBeDeleted($this);

            DB::transaction(function (): void {
                $this->deleteRecursive(false);
            });

            return;
        }

        foreach ($this->children()->get() as $child) {
            $child->deleteRecursive(false);
        }

        foreach ($this->files()->get() as $file) {
            $file->delete();
        }

        $this->delete();
    }
}
