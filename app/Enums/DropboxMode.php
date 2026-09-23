<?php

namespace App\Enums;

enum DropboxMode: string
{
    case Off = 'off';
    case Preview = 'preview';
    case Import = 'import';
    case Bidirectional = 'bidirectional';
    case AppOnly = 'app_only';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Aus', self::Preview => 'Prüfen', self::Import => 'Excel → App',
            self::Bidirectional => 'Beidseitig', self::AppOnly => 'Nur App',
        };
    }

    public function active(): bool
    {
        return in_array($this, [self::Preview, self::Import, self::Bidirectional], true);
    }
}
