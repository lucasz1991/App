<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeVacationPolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['non_working_dates' => 'array', 'revision' => 'integer', 'approved_at' => 'immutable_datetime'];
}
