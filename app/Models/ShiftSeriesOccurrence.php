<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSeriesOccurrence extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['service_date' => 'immutable_date'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
