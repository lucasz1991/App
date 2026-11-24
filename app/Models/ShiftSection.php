<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;

class ShiftSection extends Model
{
    use HasZonedSchedule;

    protected $casts = ['activity_requirements' => 'array'];

    protected $guarded = ['id'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
