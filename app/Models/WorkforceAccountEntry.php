<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforceAccountEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'snapshot' => 'array'];
}
