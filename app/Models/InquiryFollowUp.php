<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryFollowUp extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    protected function dueAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function completedAt(): Attribute
    {
        return $this->utcInstant();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(OperationInquiry::class, 'operation_inquiry_id');
    }
}
