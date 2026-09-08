<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class SupportCaseAttachment extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['name' => 'encrypted', 'expires_at' => 'datetime'];
}
