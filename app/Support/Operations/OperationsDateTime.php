<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class OperationsDateTime
{
    public static function local(string $value, string $timezone, string $field = 'startsAt'): CarbonImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
            $value = str_replace('T', ' ', trim($value));
            $format = strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $zone);
            if (! $date || $date->format($format) !== $value) {
                throw new \InvalidArgumentException;
            }
            // Reject both nonexistent spring-forward and ambiguous fall-back wall times.
            $wall = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'))->getTimestamp();
            $offsets = [$zone->getOffset($date)];
            foreach ($zone->getTransitions($wall - 86400, $wall + 86400) ?: [] as $transition) {
                $offsets[] = $transition['offset'];
            }
            $matches = 0;
            foreach (array_unique($offsets) as $offset) {
                $candidate = (new DateTimeImmutable('@'.($wall - $offset)))->setTimezone($zone);
                if ($candidate->format($format) === $value) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                throw new \InvalidArgumentException;
            }

            return CarbonImmutable::instance($date)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Datum und Uhrzeit sind ungültig oder wegen der Zeitumstellung nicht eindeutig.']);
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public static function interval(string $start, string $end, string $timezone): array
    {
        $from = self::local($start, $timezone);
        $to = self::local($end, $timezone, 'endsAt');
        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['endsAt' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        return [$from, $to];
    }

    public static function duration(int $seconds): string
    {
        return sprintf('%d:%02d h', intdiv(max(0, $seconds), 3600), intdiv(max(0, $seconds) % 3600, 60));
    }

    public static function workTimePreview(array $values, ?string $timezone): string
    {
        if (blank($timezone) || ! is_string($values['starts_at'] ?? null) || blank($values['starts_at'])
            || ! is_string($values['ends_at'] ?? null) || blank($values['ends_at']) || blank($values['pause_minutes'] ?? null)) {
            return 'Für die Nettozeit Beginn, Ende, Pause und Zeitzone vollständig angeben.';
        }

        try {
            [$start, $end] = self::interval($values['starts_at'], $values['ends_at'], $timezone);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $pause = filter_var($values['pause_minutes'], FILTER_VALIDATE_INT);
        if ($pause === false || $pause < 0 || $pause * 60 >= $seconds) {
            return 'Die Pause muss in ganzen Minuten angegeben werden und kürzer als die Arbeitszeit sein.';
        }
        if ($seconds > 86400) {
            return 'Der Zeitraum darf höchstens 24 Stunden umfassen.';
        }
        if ($end->isFuture()) {
            return 'Das Ende liegt in der Zukunft.';
        }

        return self::duration($seconds - $pause * 60);
    }
}
