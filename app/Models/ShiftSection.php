<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;

class ShiftSection extends Model
{
    use HasZonedSchedule;

    protected $guarded = ['id'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
