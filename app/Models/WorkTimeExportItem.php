<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkTimeExportItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'revision' => 'integer'];
}
