<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LexwareExportRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'positions', 'imported_codes', 'target_key'];

    protected $casts = [
        'payload' => 'encrypted', 'positions' => 'encrypted:array', 'imported_codes' => 'encrypted:array',
        'closing_revision' => 'integer', 'profile_version' => 'integer',
        'created_at' => 'immutable_datetime', 'result_at' => 'immutable_datetime',
    ];
}
