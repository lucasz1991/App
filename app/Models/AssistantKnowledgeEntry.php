<?php

namespace App\Models;

use App\Services\Ai\AssistantKnowledgePool;
use App\Support\Ai\AssistantKnowledgeDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssistantKnowledgeEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'topic_id',
        'title',
        'summary',
        'content',
        'keywords',
        'is_active',
        'include_in_baseline',
        'sort_order',
    ];

    protected $hidden = [
        'source_hash',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_active' => 'boolean',
        'include_in_baseline' => 'boolean',
        'sort_order' => 'integer',
        'imported_at' => 'datetime',
    ];

    /** @return BelongsTo<AssistantKnowledgeTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(AssistantKnowledgeTopic::class, 'topic_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('topic', static fn (Builder $topic): Builder => $topic->active());
    }

    protected static function booted(): void
    {
        $flush = static fn (): bool => app(AssistantKnowledgePool::class)->forgetCache();

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    /** @return Attribute<string|null, never> */
    protected function sourceUrl(): Attribute
    {
        return Attribute::get(
            static fn (mixed $value): ?string => AssistantKnowledgeDefaults::isAllowedSourceUrl($value)
                ? $value
                : null,
        );
    }
}
