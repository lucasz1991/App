<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** UTC-only fields on models whose legacy date columns use the app timezone. */
class UtcImmutableDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC'))
            ->utc()->format('Y-m-d H:i:s.u');
    }
}
