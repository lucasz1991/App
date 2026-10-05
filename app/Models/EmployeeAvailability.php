<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAvailability extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['from' => 'immutable_date', 'until' => 'immutable_date', 'weekdays' => 'array', 'whole_day' => 'boolean', 'revision' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
