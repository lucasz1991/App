<?php

namespace App\Models;

use App\Enums\MarketingCreativeFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCreativeVariant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'marketing_creative_id',
        'format',
        'builder_data',
        'html',
        'css',
        'content_hash',
        'version',
    ];

    protected $casts = [
        'format' => MarketingCreativeFormat::class,
        'builder_data' => 'array',
        'version' => 'integer',
    ];

    public function creative(): BelongsTo
    {
        return $this->belongsTo(MarketingCreative::class, 'marketing_creative_id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(MarketingRender::class);
    }
}
