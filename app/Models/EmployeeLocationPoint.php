<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLocationPoint extends Model
{
    use \App\Support\EmployeeLocation\HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['position'];

    protected $casts = ['position' => 'encrypted:array', 'measured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'accuracy_m' => 'float'];
}
