<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Aktionen, für die je Rolle Rechte vergeben werden können.
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
     * Prüft, ob eine Rolle die Aktion in diesem Ordner ausführen darf.
     * Ohne gesetzte Rechte (null) ist der Ordner für alle freigegeben.
     * Rollen außer admin/staff zählen als 'user'; Admins dürfen immer alles.
     */
    public function allowsForRole(?string $role, string $action): bool
    {
        if ($role === 'admin') {
            return true;
        }

        $permissions = $this->permissions;

        if (! is_array($permissions) || $permissions === []) {
            return true;
        }

        $normalized = in_array($role, ['admin', 'staff'], true) ? $role : 'user';

        return (bool) ($permissions[$normalized][$action] ?? false);
    }

    /* ------------------------------------------------------------------
     * Sichtbarkeit: Zeitfenster (visible_from/visible_until) + Team-Freigabe
     * (visible_teams). Greift zusaetzlich zu den Rollen-Rechten und nur im
     * nutzerseitigen Rollenmodus; Admins/Verwaltung sehen im Management alles.
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
    public function deleteRecursive(): void
    {
        foreach ($this->children()->get() as $child) {
            $child->deleteRecursive();
        }

        foreach ($this->files()->get() as $file) {
            $file->delete();
        }

        $this->delete();
    }
}
