<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCaseMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['body' => 'encrypted', 'from_support' => 'boolean'];
}
