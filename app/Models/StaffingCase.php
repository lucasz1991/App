<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffingCase extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['contacts' => 'array', 'due_at' => 'immutable_datetime', 'handed_over_at' => 'immutable_datetime', 'revision' => 'integer'];

    public function shift()
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }
}
