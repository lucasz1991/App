<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileLocationCode extends Model
{
    use \App\Support\EmployeeLocation\HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
}
