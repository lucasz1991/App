<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsRateRule extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['configuration' => 'array', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'revision' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
