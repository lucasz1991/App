<?php

namespace App\Models;

use App\Enums\DropboxMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DropboxConnection extends Model
{
    protected $attributes = ['generation' => 1, 'mode' => 'off', 'closing' => false];

    protected $guarded = ['id'];

    protected $hidden = ['app_secret', 'access_token', 'refresh_token'];

    protected $casts = [
        'mode' => DropboxMode::class, 'generation' => 'integer', 'settings' => 'array', 'scopes' => 'array',
        'app_secret' => 'encrypted', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted',
        'expires_at' => 'immutable_datetime', 'webhook_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime',
        'preview_at' => 'immutable_datetime', 'imported_at' => 'immutable_datetime', 'exported_at' => 'immutable_datetime',
        'closing' => 'boolean', 'closing_started_at' => 'immutable_datetime',
    ];

    public function option(string $key): mixed
    {
        return $this->settings[$key] ?? config('dropbox.defaults.'.$key);
    }

    public function isLocalImport(): bool
    {
        return ($this->settings['source'] ?? null) === 'local';
    }

    public function scopeRemote(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('settings->source')->orWhere('settings->source', '!=', 'local'));
    }
}
