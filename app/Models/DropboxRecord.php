<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DropboxRecord extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];
}
