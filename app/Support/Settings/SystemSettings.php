<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Allgemeine Systemwerte der Admin-Einstellungen.
 *
 * Gruppe, Schluessel und Default stehen nur hier. Die Middleware
 * MaintenanceMode liest denselben Datensatz ueber einen eigenen, 60 Sekunden
 * gueltigen Cache-Eintrag; er wird beim Umschalten sofort verworfen.
 */
final class SystemSettings
{
    public const GROUP = 'system';

    public const MAINTENANCE_MODE = 'maintenance_mode';

    /** Cache-Schluessel der Middleware App\Http\Middleware\MaintenanceMode. */
    public const MAINTENANCE_CACHE_KEY = 'maintenance_mode';

    /** Wartungsmodus: nur Admins koennen die App nutzen. Immer frisch aus der DB. */
    public static function maintenanceMode(): bool
    {
        return (bool) (Setting::getValueUncached(self::GROUP, self::MAINTENANCE_MODE) ?? false);
    }

    public static function setMaintenanceMode(bool $enabled): void
    {
        Setting::setValue(self::GROUP, self::MAINTENANCE_MODE, $enabled);

        // Der MaintenanceMode-Middleware-Cache liest den Schluessel 'maintenance_mode'
        // 60s lang aus dem Cache — nach dem Umschalten sofort invalidieren.
        Cache::forget(self::MAINTENANCE_CACHE_KEY);
    }
}
