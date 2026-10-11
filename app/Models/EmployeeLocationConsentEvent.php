<?php

namespace App\Models;

use App\Support\EmployeeLocation\HasUtcDates;
use Illuminate\Database\Eloquent\Model;

class EmployeeLocationConsentEvent extends Model
{
    use HasUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['explanation_text'];

    protected $casts = ['occurred_at' => 'immutable_datetime'];
}
