<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CustomerCapacityReservation extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];

    protected function startsAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function endsAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function releasedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
