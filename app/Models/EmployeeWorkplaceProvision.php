<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeWorkplaceProvision extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['initial_password'];

    protected $casts = ['initial_password' => 'encrypted', 'approved_at' => 'datetime', 'signed_in_at' => 'datetime', 'password_expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
