<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WorkTimeEvent extends Model
{
    use HasUtcInstants;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];

    protected function occurredAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function receivedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
