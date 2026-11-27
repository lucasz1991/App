<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LexwareProfile extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'mappings' => 'array',
        'active' => 'boolean',
        'config_version' => 'integer',
        'revision' => 'integer',
        'tested_version' => 'integer',
        'tested_at' => 'immutable_datetime',
    ];
}
