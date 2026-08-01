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

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
