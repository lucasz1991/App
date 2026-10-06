<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftDependency extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['predecessor_id' => 'integer', 'successor_id' => 'integer', 'revision' => 'integer', 'transfer_minutes' => 'integer', 'same_employee' => 'boolean'];
}
