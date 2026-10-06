<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CustomerPortalPublication extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'revision' => 'integer', 'source_revision' => 'integer', 'file_size' => 'integer', 'service_starts_at' => 'immutable_datetime', 'service_ends_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];

    protected function serviceStartsAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function serviceEndsAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function reviewedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function publishedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function withdrawnAt(): Attribute
    {
        return $this->utcInstant();
    }
}
