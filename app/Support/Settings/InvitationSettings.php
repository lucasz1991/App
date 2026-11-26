<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * Werte fuer Mitarbeiter-Einladungen.
 *
 * Gruppe, Schluessel und Default stehen nur hier.
 */
final class InvitationSettings
{
    public const GROUP = 'invitations';

    public const EXPIRY_DAYS = 'expiry_days';

    /** Fallback, wenn nichts oder ein unbrauchbarer Wert gespeichert ist. */
    public const DEFAULT_EXPIRY_DAYS = 7;

    /**
     * Gueltigkeit neuer Einladungslinks in Tagen (immer > 0).
     *
     * Standard ist der gecachte Lesezugriff (Einladungsdialog); die
     * Einstellungsseite liest mit $uncached = true direkt aus der DB.
     */
    public static function expiryDays(bool $uncached = false): int
    {
        $stored = $uncached
            ? Setting::getValueUncached(self::GROUP, self::EXPIRY_DAYS)
            : Setting::getValue(self::GROUP, self::EXPIRY_DAYS);

        $days = (int) ($stored ?? self::DEFAULT_EXPIRY_DAYS);

        return $days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS;
    }

    public static function setExpiryDays(int $days): void
    {
        Setting::setValue(self::GROUP, self::EXPIRY_DAYS, $days);
    }
}
