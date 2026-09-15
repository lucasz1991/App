<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeQualification extends Model
{
    protected $attributes = ['revision' => 1, 'status' => 'pending'];

    protected $guarded = ['id'];

    protected $casts = ['valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'reviewed_at' => 'immutable_datetime', 'revision' => 'integer'];

    protected $hidden = ['evidence_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class, 'qualification_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
