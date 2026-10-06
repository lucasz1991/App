<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalSubmissionItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array'];
}
