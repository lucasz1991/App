<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DropboxConflict extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'decision' => 'array'];
}
