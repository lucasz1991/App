<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalExcelImport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['summary' => 'array', 'processed' => 'integer'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(DropboxSource::class, 'source_id');
    }
}
