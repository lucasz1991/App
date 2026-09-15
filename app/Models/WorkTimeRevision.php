<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkTimeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'revision' => 'integer', 'created_at' => 'immutable_datetime'];
}
