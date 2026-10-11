<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAppRuntimeReceipt extends Model
{
    use HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'session_permission_grant_id', 'sessionPermissionGrant'];

    protected $casts = ['user_id' => 'integer', 'session_permission_grant_id' => 'integer', 'received_at' => 'immutable_datetime'];

    public function sessionPermissionGrant(): BelongsTo
    {
        return $this->belongsTo(SessionPermissionGrant::class);
    }
}
