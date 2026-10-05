<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WorkTimeCaptureDevice extends Model
{
    use HasUtcInstants;

    protected function createdAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function updatedAt(): Attribute
    {
        return $this->utcInstant();
    }

    public function freshTimestamp()
    {
        return CarbonImmutable::now('UTC');
    }

    protected $guarded = ['id'];

    protected $hidden = ['encryption_key'];

    protected $casts = ['encryption_key' => 'encrypted', 'last_sequence' => 'integer', 'revoked_at' => 'immutable_datetime'];
}
