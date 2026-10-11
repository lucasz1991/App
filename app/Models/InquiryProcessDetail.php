<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class InquiryProcessDetail extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'due_at' => 'immutable_datetime'];

    protected function dueAt(): Attribute
    {
        return $this->utcInstant();
    }
}
