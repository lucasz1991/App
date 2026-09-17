<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePayrollReference extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer'];
}
