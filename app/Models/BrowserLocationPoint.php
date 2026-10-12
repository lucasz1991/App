<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;

/** A replaceable last point per login, never a browser movement history. */
class BrowserLocationPoint extends Model
{
    use HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['position', 'event_id', 'event_hash'];

    protected $casts = [
        'position' => 'encrypted:array', 'user_id' => 'integer', 'session_permission_grant_id' => 'integer',
        'accuracy_m' => 'float', 'measured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
    ];
}
