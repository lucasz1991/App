<?php

namespace App\Console\Commands;

use App\Services\DeviceManagement\MicrosoftEmployeeImportService;
use Illuminate\Console\Command;
use Throwable;

final class ImportMicrosoftEmployees extends Command
{
    protected $signature = 'devices:microsoft-users:import
        {--apply : Explizit freigegebene Pilotkonten inaktiv anlegen; ohne diese Option nur Vorschau}';

    protected $description = 'Prüft maximal 20 ausdrücklich freigegebene Entra-Mitarbeiter-IDs; kein Tenant-Scan und keine Aktivierung.';

    public function handle(MicrosoftEmployeeImportService $service): int
    {
        try {
            $result = $this->option('apply') ? $service->import() : $service->preview();
            $this->line($result['message']);
            $this->table(['Modus', 'Angefragt', 'Geeignet', 'Neu', 'Würde anlegen', 'Vorhanden', 'Konflikte', 'Übersprungen'], [[
                $result['mode'], $result['requested'], $result['eligible'], $result['created'],
                $result['would_create'], $result['existing'], $result['conflicts'], $result['skipped'],
            ]]);

            return in_array($result['status'], ['preview', 'success'], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Das Importergebnis konnte nicht sicher bestätigt werden. Vor einer Wiederholung den Mitarbeiterbestand sowie Pilotfreigabe, Microsoft-Rechte und Datenbank prüfen.');

            return self::FAILURE;
        }
    }
}
