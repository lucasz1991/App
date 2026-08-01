<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasZonedSchedule
{
    protected function startsAt(): Attribute
    {
        return $this->zonedDateTimeAttribute();
    }

    protected function endsAt(): Attribute
    {
        return $this->zonedDateTimeAttribute();
    }

    private function zonedDateTimeAttribute(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?CarbonImmutable {
                if ($value === null) {
                    return null;
                }

                return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC')
                    ->setTimezone($this->scheduleTimezone());
            },
            set: function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                $dateTime = $value instanceof CarbonInterface
                    ? CarbonImmutable::instance($value)
                    : CarbonImmutable::parse($value, $this->scheduleTimezone());

                return $dateTime->utc()->format('Y-m-d H:i:s');
            },
        );
    }

    private function scheduleTimezone(): string
    {
        $timezone = (string) ($this->attributes['timezone'] ?? config('app.timezone', 'Europe/Berlin'));

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'Europe/Berlin');
        }
    }
}
