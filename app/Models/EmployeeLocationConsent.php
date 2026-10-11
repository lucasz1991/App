<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLocationConsent extends Model
{
    use \App\Support\EmployeeLocation\HasUtcDates;

    protected $guarded = ['id'];

    protected $casts = ['accepted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime', 'explanation_version' => 'integer', 'installation_id' => 'integer', 'device_assignment_id' => 'integer'];
}
