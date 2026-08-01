<?php

namespace App\Enums;

enum ShiftAssignmentStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Angefragt',
            self::Confirmed => 'Bestätigt',
            self::Declined => 'Abgelehnt',
            self::Cancelled => 'Storniert',
        };
    }

    public function blocksAvailability(): bool
    {
        return in_array($this, [self::Requested, self::Confirmed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function blockingValues(): array
    {
        return [self::Requested->value, self::Confirmed->value];
    }
}
