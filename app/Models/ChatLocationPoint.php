<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;

class ChatLocationPoint extends Model
{
    use HasUtcDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['position'];

    protected $casts = [
        'position' => 'encrypted:array', 'accuracy_m' => 'float',
        'measured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
    ];
}
