<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Angefragt',
            self::Confirmed => 'Bestätigt',
            self::Planned => 'Geplant',
            self::InProgress => 'In Durchführung',
            self::Completed => 'Abgeschlossen',
            self::Invoiced => 'Abgerechnet',
            self::Cancelled => 'Storniert',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
