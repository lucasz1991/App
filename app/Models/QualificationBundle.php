<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualificationBundle extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['requirements' => 'array', 'version' => 'integer', 'revision' => 'integer', 'approved_at' => 'immutable_datetime'];
}
