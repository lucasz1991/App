<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidgetPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'widget_key',
        'position',
        'size',
        'hidden',
    ];

    protected $casts = [
        'position' => 'integer',
        'hidden' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
