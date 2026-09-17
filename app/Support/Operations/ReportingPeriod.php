<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportingPeriod
{
    public static function bounds(string $from, string $until): array
    {
        Validator::make(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $start = CarbonImmutable::parse($from, $zone);
        $end = CarbonImmutable::parse($until, $zone)->addDay();
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['until' => 'Maximal 366 Tage auswählen.']);
        }

        return [$start->utc(), $end->utc()];
    }

    public static function apply(Builder $query, string $from, string $until, bool $overlap = false): Builder
    {
        [$start, $end] = self::bounds($from, $until);

        return $overlap ? $query->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            : $query->where('starts_at', '>=', $start)->where('starts_at', '<', $end);
    }
}
