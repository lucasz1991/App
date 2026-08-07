<?php

namespace App\Models;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingRenderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketingRender extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'marketing_creative_id',
        'marketing_creative_variant_id',
        'format',
        'fingerprint',
        'status',
        'disk',
        'path',
        'mime_type',
        'width',
        'height',
        'error',
        'rendered_at',
        'created_by',
    ];

    protected $casts = [
        'format' => MarketingCreativeFormat::class,
        'status' => MarketingRenderStatus::class,
        'width' => 'integer',
        'height' => 'integer',
        'rendered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $render): void {
            if (blank($render->public_id)) {
                $render->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(MarketingCreative::class, 'marketing_creative_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MarketingCreativeVariant::class, 'marketing_creative_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
