<?php

namespace App\Support\EmployeeLocation;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

trait HasUtcDates
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s.u';
    }

    protected function asDateTime($value)
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value, 'UTC');
        }

        return Carbon::parse($value, 'UTC')->utc();
    }
}
