<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;

class SessionPermissionGrant extends Model
{
    use HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['uuid', 'session_hash', 'session_identifier'];

    protected $casts = [
        'session_identifier' => 'encrypted', 'user_id' => 'integer',
        'last_activity_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
        'push_version' => 'integer', 'location_version' => 'integer',
        'push_decided_at' => 'immutable_datetime', 'location_decided_at' => 'immutable_datetime',
    ];
}
