<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DropboxWorkItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'generation' => 'integer', 'requested' => 'integer', 'completed' => 'integer', 'attempts' => 'integer', 'available_at' => 'immutable_datetime', 'queued_until' => 'immutable_datetime', 'running_until' => 'immutable_datetime'];
}
