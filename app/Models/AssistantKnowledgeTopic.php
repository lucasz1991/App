<?php

namespace App\Models;

use App\Services\Ai\AssistantKnowledgePool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssistantKnowledgeTopic extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return HasMany<AssistantKnowledgeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AssistantKnowledgeEntry::class, 'topic_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        $flush = static fn (): bool => app(AssistantKnowledgePool::class)->forgetCache();

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }
}
