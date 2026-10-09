<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class StaffingAutomationRun extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['shift_id' => 'integer', 'supervising_user_id' => 'integer', 'plan_revision' => 'integer', 'settings_revision' => 'integer', 'wave_count' => 'integer', 'revision' => 'integer', 'basis' => 'array'];

    protected function nextRunAt(): Attribute
    {
        return $this->utcInstant();
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function offer()
    {
        return $this->belongsTo(ShiftOffer::class, 'offer_id');
    }
}
