<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningTeam extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['user_ids' => 'array', 'revision' => 'integer', 'is_active' => 'boolean'];
}
