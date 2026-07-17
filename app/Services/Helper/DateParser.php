<?php

namespace App\Services\Helper;

use Carbon\Carbon;

final class DateParser
{
    /**
     * UVS Date -> Y-m-d
     */
    public static function date(null|string|int|float $raw): ?string
    {
        $dt = self::toCarbon($raw, false);
        return $dt?->toDateString();
    }

    /**
     * UVS DateTime -> Y-m-d H:i:s
     * Wenn nur Datum geliefert wird, wird 00:00:00 gesetzt.
     */
    public static function dateTime(null|string|int|float $raw): ?string
    {
        $dt = self::toCarbon($raw, true);
        return $dt?->toDateTimeString();
    }

    private static function toCarbon(null|string|int|float $raw, bool $withTime): ?Carbon
    {
        $v = self::sanitize($raw);
        if ($v === null) return null;

        $hasTime = (bool) preg_match('/\d{1,2}:\d{2}/', $v);

        // Datetime erwartet, UVS liefert aber nur Datum -> startOfDay
        if ($withTime && !$hasTime) {
            $d = self::toCarbon($v, false);
            return $d?->startOfDay();
        }

        $formats = $withTime
            ? [
                'Y/m/d H:i:s', 'Y/m/d H:i',
                'Y-m-d H:i:s', 'Y-m-d H:i',
                'Y.m.d H:i:s', 'Y.m.d H:i',
                'd.m.Y H:i:s', 'd.m.y H:i:s',
                'd.m.Y H:i',   'd.m.y H:i',
                'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s',
            ]
            : [
                'Y/m/d', 'Y-m-d', 'Y.m.d',
                'd.m.Y', 'd.m.y',
            ];

        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $v);
                if ($dt !== false) return $dt;
            } catch (\Throwable) {
            }
        }

        // letzter Fallback – niemals crashen
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sanitize(null|string|int|float $raw): ?string
    {
        $v = trim((string) $raw);

        // UVS Platzhalter / Müll
        if (
            $v === '' || $v === '0' ||
            $v === '/' || $v === '//' ||
            $v === '00.00.0000' || $v === '00.00.00' ||
            $v === '0000-00-00' || $v === '0000-00-00 00:00:00'
        ) {
            return null;
        }

        // führenden Müll entfernen: "//11.06.20" -> "11.06.20"
        $v = preg_replace('/^[^\d]+/', '', $v) ?? $v;

        if ($v === '' || !preg_match('/\d/', $v)) {
            return null;
        }

        return $v; // Separatoren bleiben, Formate decken / - . ab
    }
}
