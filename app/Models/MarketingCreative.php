<?php

namespace App\Models;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MarketingCreative extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'type',
        'status',
        'title',
        'shared_content',
        'approved_by',
        'approved_at',
        'approval_dependency_hash',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => MarketingCreativeType::class,
        'status' => MarketingCreativeStatus::class,
        'shared_content' => 'array',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $creative): void {
            if (blank($creative->public_id)) {
                $creative->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MarketingCreativeVariant::class)->orderBy('id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(MarketingRender::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', MarketingCreativeStatus::Archived->value);
    }
}
