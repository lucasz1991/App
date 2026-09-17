<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftSeries extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['definition' => 'array'];

    public function occurrences()
    {
        return $this->hasMany(ShiftSeriesOccurrence::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
