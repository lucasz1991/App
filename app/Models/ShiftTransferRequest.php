<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTransferRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'source_plan_revision' => 'integer', 'target_plan_revision' => 'integer'];

    public function source()
    {
        return $this->belongsTo(ShiftAssignment::class, 'source_assignment_id');
    }

    public function target()
    {
        return $this->belongsTo(ShiftAssignment::class, 'target_assignment_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
