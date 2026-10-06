<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsClosingRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
}
