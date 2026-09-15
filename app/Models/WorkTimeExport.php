<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTimeExport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(WorkTimeExportItem::class);
    }
}
