<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasUtcInstants
{
    protected function utcInstant(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
            set: fn ($value) => $value === null || $value === '' ? null : ($value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC'))->utc()->format('Y-m-d H:i:s'),
        );
    }
}
