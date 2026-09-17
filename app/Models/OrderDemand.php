<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;

class OrderDemand extends Model
{
    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'required_staff' => 'integer'];

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
