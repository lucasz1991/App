<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['definition' => 'array', 'revision' => 'integer'];
}
