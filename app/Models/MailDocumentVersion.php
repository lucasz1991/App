<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MailDocumentVersion extends Model
{
    protected $fillable = [
        'public_id', 'mail_document_id', 'revision', 'action', 'builder_data',
        'html', 'css', 'content_hash', 'was_published', 'created_by',
    ];

    protected $casts = [
        'builder_data' => 'array',
        'revision' => 'integer',
        'was_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MailDocument::class, 'mail_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
