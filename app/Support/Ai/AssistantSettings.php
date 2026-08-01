<?php

namespace App\Support\Ai;

use App\Models\Setting;
use Throwable;

final class AssistantSettings
{
    public const GROUP = 'assistant';

    public const KEY = 'enabled';

    /**
     * Der Assistent bleibt bei Bestandsinstallationen ohne gespeicherten Wert
     * aktiv. Bei einer voruebergehend nicht erreichbaren Settings-Tabelle
     * gilt derselbe kompatible Standard; die eigentliche Nutzerfreigabe wird
     * weiterhin separat durch AssistantAccess geprueft.
     */
    public static function enabled(bool $uncached = false): bool
    {
        try {
            $value = $uncached
                ? Setting::getValueUncached(self::GROUP, self::KEY)
                : Setting::getValue(self::GROUP, self::KEY);
        } catch (Throwable) {
            return true;
        }

        return $value === null ? true : (bool) $value;
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::setValue(self::GROUP, self::KEY, $enabled);
    }
}
