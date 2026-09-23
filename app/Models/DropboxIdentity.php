<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use Illuminate\Database\Eloquent\Model;

class DropboxIdentity extends Model
{
    use CapturesDropboxChanges;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'revision' => 'integer'];
}
