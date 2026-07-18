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
    ];

    protected $casts = [
        'permissions' => 'array',
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
