<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftBundleSnapshot extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['requirements' => 'array', 'bundle_version' => 'integer', 'shift_revision' => 'integer'];
}
