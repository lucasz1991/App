<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsCostRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['hourly_cents' => 'encrypted', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
