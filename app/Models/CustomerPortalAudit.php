<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalAudit extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'revision' => 'integer'];
}
