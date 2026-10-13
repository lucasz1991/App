<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;

/** Channel receipts record preparation/enqueueing, never provider delivery. */
class EmployeeRequestNotification extends Model
{
    use HasUtcDates;

    protected $guarded = ['id'];

    protected $casts = [
        'request_id' => 'integer', 'request_revision' => 'integer', 'user_id' => 'integer',
        'requested_by' => 'integer', 'message_id' => 'integer', 'mail_id' => 'integer',
        'message_created_at' => 'immutable_datetime', 'mail_enqueued_at' => 'immutable_datetime',
        'mail_skipped_at' => 'immutable_datetime', 'push_enqueued_at' => 'immutable_datetime',
        'push_skipped_at' => 'immutable_datetime', 'enqueued_at' => 'immutable_datetime',
        'push_claimed_at' => 'immutable_datetime',
        'skipped_at' => 'immutable_datetime', 'attempts' => 'integer',
        'mail_failure_version' => 'integer',
    ];
}
