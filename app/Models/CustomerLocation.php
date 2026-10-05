<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'is_active' => 'boolean'];
}
