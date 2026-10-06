<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['body' => 'encrypted'];
}
