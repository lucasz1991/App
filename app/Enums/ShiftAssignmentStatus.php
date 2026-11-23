<?php

namespace App\Enums;

enum ShiftAssignmentStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Reported = 'reported';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Angefragt',
            self::Confirmed => 'Bestätigt',
            self::Declined => 'Abgelehnt',
            self::Cancelled => 'Storniert',
            self::Reported => 'Historisch gemeldet',
        };
    }

    /** Font-Awesome-5-Pro-Name des Statussymbols (Stil "far"). */
    public function icon(): string
    {
        return match ($this) {
            self::Confirmed => 'fa-check-circle',
            self::Declined => 'fa-times-circle',
            self::Cancelled => 'fa-ban',
            default => 'fa-clock',
        };
    }

    /** Farbton fuer x-ui.badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Confirmed => 'green',
            self::Declined, self::Cancelled => 'red',
            self::Reported => 'slate',
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
