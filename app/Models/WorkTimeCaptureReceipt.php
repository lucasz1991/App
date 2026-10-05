<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTimeCaptureReceipt extends Model
{
    use HasUtcInstants;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'sequence' => 'integer', 'applied_revision' => 'integer', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];

    protected function occurredAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function receivedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function reviewedAt(): Attribute
    {
        return $this->utcInstant();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WorkTimeCaptureDevice::class, 'work_time_capture_device_id');
    }
}
