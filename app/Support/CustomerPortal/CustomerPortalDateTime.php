<?php

namespace App\Support\CustomerPortal;

use App\Support\Operations\OperationsDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CustomerPortalDateTime
{
    public static function local(string $value, string $timezone, string $field = 'starts_at'): CarbonImmutable
    {
        $value = trim($value);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(:\d{2})?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts)) {
            return OperationsDateTime::local($value, $timezone, $field);
        }
        $canonical = $parts[1].($parts[2] ?: ':00').($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        try {
            $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
            // Explicit offsets must be a real instant of the named timezone, not a fabricated DST offset.
            if (! $at || $at->setTimezone($timezone)->format('Y-m-d\TH:i:sP') !== $canonical) {
                throw new \InvalidArgumentException;
            }

            return $at->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Datum, Zeitzone oder Zeitumstellungs-Offset ist ungültig.']);
        }
    }

    public static function interval(string $start, string $end, string $timezone): array
    {
        $from = self::local($start, $timezone);
        $to = self::local($end, $timezone, 'ends_at');
        if ($to->lte($from)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        return [$from, $to];
    }
}
