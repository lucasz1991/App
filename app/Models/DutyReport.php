<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DutyReport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['plan_revision' => 'integer', 'revision' => 'integer', 'delay_minutes' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
