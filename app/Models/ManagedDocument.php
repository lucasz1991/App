<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ManagedDocument extends Model
{
    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_TEAMS = 'teams';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'audience_type',
        'notify_on_update',
        'is_active',
        'content_updated_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'notify_on_update' => 'boolean',
        'is_active' => 'boolean',
        'content_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ManagedDocument $document): void {
            if (blank($document->slug)) {
                $base = Str::slug($document->title) ?: 'datei';
                $slug = $base;
                $suffix = 2;

                while (static::query()->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $suffix++;
                }

                $document->slug = $slug;
            }
        });

        static::deleting(function (ManagedDocument $document): void {
            $document->versions()->with('file')->get()->each->delete();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ManagedDocumentVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(ManagedDocumentVersion::class)->where('is_current', true);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'managed_document_team')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query
            ->where('is_active', true)
            ->where(function (Builder $audienceQuery) use ($user): void {
                $audienceQuery
                    ->where('audience_type', self::AUDIENCE_ALL)
                    ->orWhere(function (Builder $teamQuery) use ($user): void {
                        $teamQuery
                            ->where('audience_type', self::AUDIENCE_TEAMS)
                            ->whereHas('teams.users', fn (Builder $members) => $members->where('users.id', $user->id));
                    });
            });
    }

    public function canBeViewedBy(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function recipientQuery(): Builder
    {
        $query = User::query()
            ->where('status', true)
            ->where('role', '!=', 'admin');

        if ($this->audience_type === self::AUDIENCE_TEAMS) {
            $teamIds = $this->teams()->pluck('teams.id');
            $query->whereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $teamIds));
        }

        return $query->distinct();
    }
}
