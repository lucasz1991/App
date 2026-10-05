<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryFollowUp extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(OperationInquiry::class, 'operation_inquiry_id');
    }
}
