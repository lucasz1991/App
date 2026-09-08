<?php

namespace App\Services\DeviceManagement;

use Illuminate\Validation\ValidationException;

final class WorkplaceProfileCatalog
{
    public const VERSION = 1;

    public static function all(): array
    {
        return [
            'railtime_basic' => ['label' => 'RailTime Basis', 'office_required' => false, 'managed' => false],
            'corporate_standard' => ['label' => 'Firmenarbeitsplatz', 'office_required' => true, 'managed' => true],
            'byod_managed' => ['label' => 'Privatgerät – verwaltet', 'office_required' => false, 'managed' => true],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw ValidationException::withMessages(['profileKey' => 'Unbekanntes Arbeitsplatzprofil.']);
    }

    public static function scope(string $key): array
    {
        $profile = self::get($key);

        return ['version' => self::VERSION, 'profile' => $key, 'label' => $profile['label'],
            'inventory' => $profile['managed'], 'automatic_programs' => $profile['managed'],
            'system_service' => $profile['managed'], 'remote_support_consent' => true,
            'file_transfer_consent' => true, 'whole_device_wipe' => false, 'continuous_location' => false,
            'notice' => $profile['managed']
                ? 'Der Verwaltungsdienst arbeitet mit Systemrechten und kann technisch auf den gesamten Rechner zugreifen. Dies ist kein isolierter Firmencontainer. Freigegebene Firmenprogramme und Einstellungen werden automatisch angewendet. Bildschirm-Fernhilfe und Dateiübertragung benötigen eine eigene Freigabe. Die Verwaltung ist widerrufbar.'
                : 'RailTime-Arbeitsansicht und Hilfe. Keine dauerhafte Systemverwaltung und keine verpflichtende Office-Lizenz.'];
    }

    public static function hash(string $key): string
    {
        return hash('sha256', json_encode(self::scope($key), JSON_THROW_ON_ERROR));
    }
}
