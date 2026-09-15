<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkTimeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
}
