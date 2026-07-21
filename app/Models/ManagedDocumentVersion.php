<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ManagedDocumentVersion extends Model
{
    protected $fillable = [
        'version_number',
        'is_current',
        'change_notes',
        'created_by',
    ];

    protected $casts = [
        'is_current' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ManagedDocumentVersion $version): void {
            $version->file?->delete();
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ManagedDocument::class, 'managed_document_id');
    }

    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
