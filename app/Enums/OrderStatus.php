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

    /** Font-Awesome-5-Pro-Name des Statussymbols (Stil "far"). */
    public function icon(): string
    {
        return match ($this) {
            self::Requested => 'fa-inbox',
            self::Confirmed => 'fa-check-circle',
            self::Planned => 'fa-calendar-check',
            self::InProgress => 'fa-play-circle',
            self::Completed => 'fa-check-circle',
            self::Invoiced => 'fa-file-invoice',
            self::Cancelled => 'fa-ban',
        };
    }

    /** Farbton fuer x-ui.badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Confirmed => 'green',
            self::Planned => 'sky',
            self::InProgress => 'cyan',
            self::Completed => 'slate',
            self::Invoiced => 'purple',
            self::Cancelled => 'red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
