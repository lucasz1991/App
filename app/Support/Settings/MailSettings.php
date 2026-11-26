<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * Mailbezogene Systemwerte der Admin-Einstellungen.
 *
 * Gruppe, Schluessel und Default stehen nur hier.
 */
final class MailSettings
{
    public const GROUP = 'mails';

    public const ADMIN_EMAIL = 'admin_email';

    /**
     * Empfaengeradresse fuer Systemnachrichten / Testmails. Immer frisch aus
     * der DB und unveraendert (kein trim, keine Formatpruefung) — leer, wenn
     * nichts hinterlegt ist.
     */
    public static function adminEmail(): string
    {
        return (string) (Setting::getValueUncached(self::GROUP, self::ADMIN_EMAIL) ?? '');
    }

    public static function setAdminEmail(string $email): void
    {
        Setting::setValue(self::GROUP, self::ADMIN_EMAIL, $email);
    }
}
