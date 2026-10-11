<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileLocationInstallation extends Model
{
    use \App\Support\EmployeeLocation\HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'permit_id', 'session_id'];

    protected $casts = ['revoked_at' => 'immutable_datetime', 'session_started_at' => 'immutable_datetime', 'permit_expires_at' => 'immutable_datetime', 'last_sample_at' => 'immutable_datetime', 'next_sample_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'user_id' => 'integer', 'device_assignment_id' => 'integer', 'consent_id' => 'integer', 'work_time_entry_id' => 'integer', 'work_revision' => 'integer'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'device_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
