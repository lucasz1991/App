<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'revision' => 'integer', 'created_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocumentRequirement::class, 'employee_document_requirement_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
