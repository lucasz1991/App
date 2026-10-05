<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InquiryProcessDetail extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'due_at' => 'immutable_datetime'];
}
