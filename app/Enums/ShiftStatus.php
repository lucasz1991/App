<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Requested = 'requested';
    case Staffed = 'staffed';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Open => 'Offen',
            self::Requested => 'Angefragt',
            self::Staffed => 'Besetzt',
            self::Confirmed => 'Bestätigt',
            self::InProgress => 'In Durchführung',
            self::Completed => 'Abgeschlossen',
            self::Cancelled => 'Storniert',
        };
    }

    /** Font-Awesome-5-Pro-Name des Statussymbols (Stil "far"). */
    public function icon(): string
    {
        return match ($this) {
            self::Completed => 'fa-check-circle',
            self::Cancelled => 'fa-ban',
            self::Confirmed, self::Staffed => 'fa-user-check',
            self::InProgress => 'fa-play-circle',
            default => 'fa-calendar-day',
        };
    }

    /** Farbton fuer x-ui.badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Completed => 'slate',
            self::Open, self::Requested => 'amber',
            self::Staffed, self::Confirmed => 'green',
            self::InProgress => 'cyan',
            self::Cancelled => 'red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
