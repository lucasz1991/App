<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DropboxSource extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['weeks' => 'array', 'progress' => 'array', 'first_seen_at' => 'immutable_datetime', 'server_modified' => 'immutable_datetime'];
}
