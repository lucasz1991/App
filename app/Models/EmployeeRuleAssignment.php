<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRuleAssignment extends Model
{
    protected $guarded = ['id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OperationsRuleProfile::class, 'operations_rule_profile_id');
    }
}
