<?php

namespace App\Services\SystemHealth;

use App\Services\DeviceManagement\ConnectorHttpClient;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftGraphDeviceClient;
use App\Services\DeviceManagement\MicrosoftGraphDeviceException;
use App\Services\DeviceManagement\Providers\ConnectorDeviceProvider;

class DeviceChecks
{
    public function run(string $id): array
    {
        if ($id === 'scheduler' || $id === 'microsoft_runtime') {
            $runtime = app(MicrosoftDeviceRuntime::class)->status();
            if ($id === 'scheduler') {
                $fresh = ($runtime['scheduler']['state'] ?? '') === 'fresh';

                return $this->result($fresh ? 'ok' : 'warning', $fresh
                    ? 'Aktueller Scheduler-Nachweis aus einem regulären geplanten Aufruf vorhanden.'
                    : 'Kein aktueller Scheduler-Nachweis vorhanden.', [
                        'Nachweisquelle: regulär geplanter Microsoft-Geräteaufruf; dieser Webtest erzeugt keinen Scheduler-Zeitstempel.',
                        'Das bestätigt nicht die erfolgreiche Ausführung sämtlicher geplanter Geschäftsaufgaben.',
                    ], $fresh ? 'runtime' : 'configuration');
            }
            if (! app(MicrosoftDeviceSettings::class)->configuration()['enabled']) {
                return $this->result('disabled', 'Microsoft-Gerätesynchronisierung ist deaktiviert.');
            }
            $run = $runtime['run'] ?? [];
            $recent = isset($run['finished_at']) && (strtotime($run['finished_at']) ?: 0) > now()->subMinutes(30)->timestamp;
            $details = ['Bestehende Laufnachweise werden nur gelesen; kein Inventarimport oder Gerätebefehl gestartet.'];
            if (! empty($runtime['issues']) || ! empty($runtime['overdue'])) {
                return $this->result('warning', 'Der Microsoft-Laufzeitstatus benötigt eine Prüfung.', $details);
            }
            if ($recent && ($run['status'] ?? '') === 'completed' && ($run['outcome'] ?? '') === 'success') {
                return $this->result('ok', 'Ein aktueller erfolgreicher Microsoft-Abruf ist nachgewiesen.', $details, 'runtime');
            }

            return $this->result('warning', 'Kein aktueller vollständig erfolgreicher Microsoft-Abruf nachgewiesen.', $details);
        }
        if ($id === 'microsoft') {
            $configuration = app(MicrosoftDeviceSettings::class)->configuration();
            if (! $configuration['enabled']) {
                return $this->result('disabled', 'Microsoft-Gerätesynchronisierung ist deaktiviert oder nicht vollständig eingerichtet.');
            }
            try {
                $client = app(MicrosoftGraphDeviceClient::class);
                $client->begin($configuration, shortProbe: true);
                $client->devices(probe: true);
                if ($configuration['intune_enabled']) {
                    $client->managedDevices(probe: true);
                }
            } catch (MicrosoftGraphDeviceException $exception) {
                $message = match ($exception->reason) {
                    'unauthorized' => 'Microsoft hat die Anmeldung abgelehnt. Gültigkeit des Client-Geheimnisses prüfen.',
                    'forbidden' => 'Microsoft-Graph-Leserechte oder Adminzustimmung fehlen.',
                    'rate_limited' => 'Microsoft begrenzt die Leseabfragen derzeit.',
                    default => 'Die begrenzte Microsoft-Leseprobe konnte nicht erfolgreich abgeschlossen werden.',
                };

                return $this->result('error', $message, [], 'connection');
            }

            return $this->result('ok', 'Microsoft-Anmeldung und begrenzter Geräte-Lesezugriff erfolgreich.', [
                'Maximal ein Ergebnis pro aktiviertem Inventar-Endpunkt; keine Gerätedaten gespeichert oder angezeigt.',
                'Besitzerzuordnung, vollständige Synchronisierung und Fernwartung werden damit nicht nachgewiesen.',
            ], 'connection');
        }
        $provider = substr($id, 7);
        if ($provider === 'simulation') {
            return $this->result('not_checked', 'Simulation ist kein Nachweis einer produktiven Geräteanbindung.');
        }
        $settings = app(DeviceManagementSettings::class);
        $configuration = $settings->providerRuntime($provider, fresh: true);
        if (! empty($configuration['configuration_error'])) {
            return $this->result('error', 'Die gespeicherte Connector-Konfiguration ist ungültig oder nicht entschlüsselbar.');
        }
        if (! ($configuration['enabled'] ?? false)) {
            return $this->result('disabled', 'Dieser Geräteconnector ist deaktiviert.');
        }
        if (empty($configuration['token'])) {
            return $this->result('not_configured', 'Für den aktivierten Connector fehlt ein nutzbares Zugriffstoken.');
        }
        $configuration['timeout'] = 5;
        $health = (new ConnectorDeviceProvider($provider, $configuration, app(ConnectorHttpClient::class), $settings))->health();

        return $this->result($health->healthy ? 'ok' : 'warning', $health->healthy
            ? 'Connector und authentifizierte Upstream-Verbindung bestätigt.'
            : 'Connector meldet keine vollständig erreichbare und authentifizierte Upstream-Verbindung.', [
                'Nur der feste Health-Endpunkt wurde gelesen. Kein Enrollment, Gerätebefehl oder Fernwartungsstart.',
                'Der Produktionsschalter und vorhandene Freigaben bleiben unverändert.',
            ], 'connection');
    }

    private function result(string $status, string $message, array $details = [], string $evidence = 'configuration'): array
    {
        return compact('status', 'message', 'details', 'evidence');
    }
}
