<?php

namespace App\Services\SystemHealth;

use App\Jobs\ProbeSystemHealthWorker;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Str;

class QueueChecks
{
    public function __construct(private readonly SystemHealthStore $store) {}

    public function target(string $id): ?array
    {
        $connection = (string) config('queue.default', 'sync');
        $queue = (string) config("queue.connections.{$connection}.queue", 'default');
        switch ($id) {
            case 'queue_devices':
                $enabled = false;
                $settings = app(DeviceManagementSettings::class);
                foreach (['openuem', 'meshcentral', 'headwind', 'nanomdm', 'identity'] as $provider) {
                    $enabled = $enabled || (bool) ($settings->providerRuntime($provider, fresh: true)['enabled'] ?? false);
                }
                if (! $enabled) {
                    return null;
                }
                $queue = (string) config('device_management.queue', 'devices');
                break;
            case 'queue_microsoft':
                if (! app(MicrosoftDeviceSettings::class)->configuration()['enabled']) {
                    return null;
                }
                $connection = $queue = 'microsoft_devices';
                break;
            case 'queue_calls':
                if (! config('call_recording.enabled')) {
                    return null;
                }
                $queue = (string) config('call_recording.queue', 'calls');
                break;
            case 'queue_push':
                if (! config('webpush.enabled')) {
                    return null;
                }
                $queue = (string) config('webpush.queue', 'default');
                break;
            case 'queue_marketing':
                $queue = (string) config('marketing.renders.queue', 'default');
                break;
        }

        return compact('connection', 'queue');
    }

    public function start(string $id, bool $force): array
    {
        $target = $this->target($id);
        if ($target === null) {
            return $this->result('disabled', 'Der zugehörige Dienst ist deaktiviert; kein Probejob gestartet.');
        }
        $configuration = config('queue.connections.'.$target['connection'], []);
        $driver = $configuration['driver'] ?? '';
        if (in_array($driver, ['sync', 'null', 'array'], true)) {
            return $this->result('warning', 'Kein separater Hintergrund-Worker konfiguriert.', ['Synchrones Ausführen ist kein Nachweis einer funktionierenden Worker-Queue.']);
        }
        if ($driver !== 'database') {
            return $this->result('not_checked', 'Für diesen Queue-Treiber ist noch keine begrenzte, belastbare Workerprobe verfügbar.', ['Die automatische Probe unterstützt derzeit die produktiv verwendeten Datenbankqueues.']);
        }
        $databaseConfiguration = config('database.connections.'.($configuration['connection'] ?? config('database.default')));
        $key = 'queue-probe:'.hash('sha256', serialize([$target, $configuration, $databaseConfiguration]));
        $lock = $this->store->lock($key.':mutation', 60);
        if (! $lock->get()) {
            return $this->result('running', 'Eine andere Prüfung bereitet dieselbe Queueprobe vor.') + ['pending' => true, '_probe_key' => $key];
        }
        try {
            $probe = $this->store->get($key);
            if ($probe && (($probe['deadline'] > now()->timestamp && empty($probe['acknowledged_at']))
                || (! empty($probe['acknowledged_at'])
                    && (strtotime($probe['acknowledged_at']) ?: 0) > now()->timestamp - SystemHealthService::TTL
                    && (! $force || $probe['started_at'] >= now()->timestamp - 5)))) {
                return $this->observe($key);
            }

            return app(BoundedInfrastructureConnections::class)->database(
                $configuration['connection'] ?? null,
                fn (Connection $database): array => $this->enqueueProbe($database, $configuration, $target, $key, $probe),
            ) ?? $this->result('not_checked', 'Für diese Queue-Datenbank ist keine sicher begrenzte Diagnoseverbindung verfügbar.', ['Kein Probejob angelegt; kein Rückfall auf eine unbeschränkte Queueverbindung.']);
        } finally {
            $lock->release();
        }
    }

    private function enqueueProbe(Connection $database, array $configuration, array $target, string $key, ?array $probe): array
    {
        $jobs = $database->table($configuration['table'] ?? 'jobs')->where('queue', $target['queue']);
        if ($probe && empty($probe['acknowledged_at']) && ! empty($probe['job_id'])
            && (clone $jobs)->where('id', $probe['job_id'])->exists()) {
            // An expired observation does not authorize piling more jobs onto the same stalled queue.
            $this->store->put($key, $probe, 86400);

            return $this->result('warning', 'Die vorherige Probe wartet noch in derselben Queue; kein weiterer Probejob angelegt.',
                ['Beobachtungszeit abgelaufen. Vor einer neuen Probe die vorhandene Queueverarbeitung prüfen; keine Aufträge automatisch entfernt.']);
        }
        $backlog = (clone $jobs)->count();
        $old = (clone $jobs)->where('available_at', '<', now()->subMinutes(15)->timestamp)->count();
        $database->transaction(function () use ($database, $configuration, $target, $key, $backlog, $old): void {
            $probe = [
                'nonce' => Str::random(48), 'started_at' => now()->timestamp,
                'deadline' => now()->timestamp + 120, 'acknowledged_at' => null,
                'backlog' => $backlog, 'old' => $old,
            ];
            // Dedicated transport uses the same bounded SQL session for reading and enqueueing.
            // Keep the public queue connection name: normal workers still consume this payload.
            $transport = new DatabaseQueue($database, $configuration['table'] ?? 'jobs', $target['queue'], $configuration['retry_after'] ?? 60, false);
            $transport->setContainer(app());
            $transport->setConnectionName($target['connection']);
            $job = (new ProbeSystemHealthWorker($key, $probe['nonce'], $target['connection'], $target['queue']))->beforeCommit();
            $probe['job_id'] = (string) $transport->push($job, '', $target['queue']);
            // A failed receipt write rolls back the private insertion, leaving no orphaned job.
            $this->store->put($key, $probe, 86400);
        });

        return $this->observe($key);
    }

    public function observe(string $key): array
    {
        $probe = $this->store->get($key);
        if (! $probe) {
            return $this->result('warning', 'Für diesen Probejob liegt kein belastbarer Nachweis mehr vor.');
        }
        $details = ['Wartende/reservierte Aufträge beim Start: '.(int) $probe['backlog'].'.',
            'Seit über 15 Minuten fällige Aufträge: '.(int) $probe['old'].'.'];
        if (! empty($probe['acknowledged_at'])) {
            $details[] = 'Ein tatsächlich reservierter Datenbank-Worker hat die isolierte Probe bestätigt.';

            return $this->result($probe['old'] > 0 ? 'warning' : 'ok', $probe['old'] > 0
                ? 'Worker bestätigt; ältere fällige Aufträge benötigen eine Prüfung.'
                : 'Echte Verarbeitung durch den Queue-Worker bestätigt.', $details, 'runtime')
                + ['_evidence_at' => $probe['acknowledged_at']];
        }
        if ($probe['deadline'] <= now()->timestamp) {
            return $this->result('warning', 'Innerhalb von 120 Sekunden wurde keine Verarbeitung nachgewiesen.',
                [...$details, 'Das beweist keinen Worker-Ausfall. Der Auftrag kann später verarbeitet werden; verspätete Bestätigungen ändern diesen abgeschlossenen Nachweis nicht.']);
        }

        return $this->result('running', 'Probejob eingeplant; echte Workerbestätigung steht noch aus.', $details)
            + ['pending' => true, '_probe_key' => $key];
    }

    private function result(string $status, string $message, array $details = [], string $evidence = 'configuration'): array
    {
        return compact('status', 'message', 'details', 'evidence');
    }
}
