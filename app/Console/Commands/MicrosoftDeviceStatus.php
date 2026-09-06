<?php

namespace App\Console\Commands;

use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use Illuminate\Console\Command;
use Throwable;

final class MicrosoftDeviceStatus extends Command
{
    protected $signature = 'devices:microsoft-status {--json : Maschinenlesbare sichere Betriebsdaten} {--probe-worker : Einen reinen Hintergrundtest ohne Microsoft-Abfrage einplanen}';

    protected $description = 'Prueft lokale Microsoft-Geraetevoraussetzungen; gibt keine Zugangsdaten oder Mitarbeiterdaten aus.';

    public function handle(MicrosoftDeviceRuntime $runtime, MicrosoftDeviceSettings $settings): int
    {
        try {
            $probeQueued = $this->option('probe-worker') ? $runtime->queueWorkerProbe() : null;
            $state = $runtime->status();
            $configuration = $settings->status();
            $report = [
                'schema_ready' => (bool) ($state['schema_ready'] ?? false),
                'queue_ready' => (bool) ($state['queue_ready'] ?? false),
                'microsoft_configured' => (bool) $configuration['configured'],
                'sync_enabled' => (bool) $configuration['enabled'],
                'scheduler' => $state['scheduler'] ?? [],
                'worker' => $state['worker'] ?? [],
                'run' => $state['run'] ?? [],
                'worker_probe' => $state['worker_probe'] ?? [],
                'overdue' => (bool) ($state['overdue'] ?? false),
                'issues' => $state['issues'] ?? [],
                'probe_queued' => $probeQueued,
            ];
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(['Pruefung', 'Ergebnis'], [
                    ['Geraetemigrationen', $report['schema_ready'] ? 'bereit' : 'fehlen'],
                    ['Queue-Konfiguration', $report['queue_ready'] ? 'bereit' : 'nicht bereit'],
                    ['Microsoft-Zugang', $report['microsoft_configured'] ? 'hinterlegt, Graph-Test separat' : 'fehlt'],
                    ['Automatischer Abruf', $report['sync_enabled'] ? 'aktiviert' : 'deaktiviert'],
                    ['Schedulerbeleg', $report['scheduler']['state'] ?? 'unknown'],
                    ['Letzter Workerbeleg', $report['worker']['checked_at'] ?? 'nicht vorhanden'],
                    ['Abgleich', $report['run']['message'] ?? 'noch kein Lauf'],
                    ['Worker-Test', $report['worker_probe']['status'] ?? 'noch nicht angefordert'],
                ]);
                foreach ($report['issues'] as $issue) {
                    $this->warn($issue['message']);
                }
                if ($probeQueued !== null) {
                    $this->info($probeQueued ? 'Reiner Worker-Test eingeplant; Bestaetigung mit erneutem Statusabruf pruefen.' : 'Kein weiterer Worker-Test eingeplant.');
                }
            }

            return $report['schema_ready'] && $report['queue_ready'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"error":"microsoft_runtime_unavailable"}');
            } else {
                $this->error('Microsoft-Betriebsstatus nicht erreichbar. Datenbank und ausstehende Migrationen pruefen.');
            }

            return self::FAILURE;
        }
    }
}
