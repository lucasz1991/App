<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WorkTimeActivity extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'is_paid' => 'boolean'];

    protected function startsAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function endsAt(): Attribute
    {
        return $this->utcInstant();
    }
}
