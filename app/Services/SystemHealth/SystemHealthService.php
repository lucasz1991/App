<?php

namespace App\Services\SystemHealth;

use Illuminate\Support\Str;
use Throwable;

/** Request-driven orchestration. No periodic overall check and no dependence on a worker. */
class SystemHealthService
{
    public const TTL = 900;

    public function __construct(
        private readonly SystemCheckRegistry $registry,
        private readonly SystemHealthStore $store,
        private readonly InfrastructureChecks $infrastructure,
        private readonly IntegrationChecks $integrations,
        private readonly DeviceChecks $devices,
        private readonly QueueChecks $queues,
    ) {}

    public function snapshot(): array
    {
        $rows = [];
        foreach ($this->registry->all() as $id => $metadata) {
            try {
                $rows[] = $this->visible($id, $this->store->get('result:'.$id), $this->registry->fingerprint($id));
            } catch (Throwable) {
                $rows[] = $this->storageFailure($id);
            }
        }

        return $rows;
    }

    public function check(string $id, bool $force = false): array
    {
        $this->registry->get($id);
        $started = microtime(true);
        $fingerprint = $this->registry->fingerprint($id);
        try {
            $this->store->assertWritable();
            $lock = $this->store->lock('check:'.$id);
            if (! $lock->get()) {
                return $this->visible($id, $this->store->get('result:'.$id), $fingerprint);
            }
        } catch (Throwable) {
            // Stateless diagnostics can still run. A queue probe without durable receipt cannot.
            if (str_starts_with($id, 'queue_')) {
                return $this->storageFailure($id);
            }
            $row = $this->normalize($id, $this->execute($id, $force), (int) round((microtime(true) - $started) * 1000));
            $row['details'][] = 'Der private Diagnosespeicher ist nicht verfügbar. Ergebnis nicht gespeichert; keine neuen Queueproben.';
            if ($row['status'] === 'ok') {
                $row['status'] = 'warning';
            }
            $row['fresh'] = false;

            return $row;
        }
        try {
            $existing = $this->store->get('result:'.$id);
            $current = $this->visible($id, $existing, $fingerprint);
            // A running test always wins over force, even from a second tab.
            if ($current['pending'] || (! $force && $current['fresh'])) {
                return $current;
            }
            $runId = (string) Str::uuid();
            $running = $this->normalize($id, ['status' => 'running', 'message' => 'Prüfung läuft.', 'pending' => true], 0);
            $running['run_id'] = $runId;
            $record = ['row' => $running, 'fingerprint' => $fingerprint, 'deadline' => now()->timestamp + 120];
            $this->store->putResult($id, $record);

            $result = $this->execute($id, $force);
            $row = $this->normalize($id, $result, (int) round((microtime(true) - $started) * 1000));
            $row['run_id'] = $runId;
            $record['row'] = $row;
            $record['probe_key'] = $result['_probe_key'] ?? null;
            // A lock lease can expire. Never overwrite a newer run or changed configuration.
            $latest = $this->store->get('result:'.$id);
            if (($latest['row']['run_id'] ?? null) !== $runId) {
                return $this->visible($id, $latest, $this->registry->fingerprint($id));
            }
            if (! hash_equals($fingerprint, $this->registry->fingerprint($id))) {
                return $this->visible($id, $record, $this->registry->fingerprint($id));
            }
            if (! $this->store->putResult($id, $record, $runId)) {
                return $this->visible($id, $this->store->get('result:'.$id), $this->registry->fingerprint($id));
            }

            return $row;
        } catch (Throwable) {
            return $this->storageFailure($id);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lease expires independently; do not turn a failed diagnostic into a page error.
            }
        }
    }

    public function poll(string $id, string $runId): array
    {
        $this->registry->get($id);
        if (! Str::isUuid($runId)) {
            throw new \InvalidArgumentException('Invalid system check run.');
        }
        $fingerprint = $this->registry->fingerprint($id);
        try {
            $record = $this->store->get('result:'.$id);
            $row = $this->visible($id, $record, $fingerprint);
            if (($record['row']['run_id'] ?? null) !== $runId || ! $row['pending']) {
                return $row;
            }
            $lock = $this->store->lock('check:'.$id);
            if (! $lock->get()) {
                return $row;
            }
            try {
                // Re-read under lock: a completed/changed/newer run is never overwritten by polling.
                $record = $this->store->get('result:'.$id);
                if (($record['row']['run_id'] ?? null) !== $runId || ($record['fingerprint'] ?? null) !== $fingerprint) {
                    return $this->visible($id, $record, $fingerprint);
                }
                if (! empty($record['probe_key'])) {
                    $result = $this->queues->observe($record['probe_key']);
                    $next = $this->normalize($id, $result, (int) $record['row']['duration_ms']);
                    $next['run_id'] = $runId;
                    // While waiting, do not make the test appear newly started every poll.
                    if ($next['pending']) {
                        $next['checked_at'] = $record['row']['checked_at'];
                    }
                    $record['row'] = $next;
                    if (! $this->store->putResult($id, $record, $runId)) {
                        return $this->visible($id, $this->store->get('result:'.$id), $this->registry->fingerprint($id));
                    }

                    return $next;
                }

                return $this->visible($id, $record, $fingerprint);
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            return $this->storageFailure($id);
        }
    }

    protected function execute(string $id, bool $force): array
    {
        try {
            return match (true) {
                in_array($id, ['application', 'database', 'cache', 'storage', 'session', 'assets'], true) => $this->infrastructure->run($id),
                str_starts_with($id, 'queue_') => $this->queues->start($id, $force),
                str_starts_with($id, 'device_'), str_starts_with($id, 'microsoft'), $id === 'scheduler' => $this->devices->run($id),
                $id === 'backups' => ['status' => 'not_checked', 'message' => 'Extern verwaltet; kein belastbarer Backup- oder Wiederherstellungsnachweis vorhanden.', 'evidence' => 'configuration'],
                default => $this->integrations->run($id),
            };
        } catch (Throwable) {
            // Never persist provider responses, URLs, credentials or exception chains.
            return ['status' => 'error', 'evidence' => 'configuration', 'message' => 'Diese Prüfung konnte nicht abgeschlossen werden. Zugehörige Konfiguration und Serverprotokolle prüfen.', 'details' => ['Die übrigen Prüfungen können unabhängig fortgesetzt werden.']];
        }
    }

    private function visible(string $id, ?array $record, string $fingerprint): array
    {
        if (! $record || ! isset($record['row'])) {
            return $this->emptyRow($id);
        }
        $row = $record['row'];
        $row['source'] = 'cache';
        $timestamp = strtotime($row['checked_at'] ?? '') ?: 0;
        $row['fresh'] = $timestamp > now()->timestamp - self::TTL
            && hash_equals($record['fingerprint'] ?? '', $fingerprint);
        if (! hash_equals($record['fingerprint'] ?? '', $fingerprint)) {
            $row['pending'] = false;
            $row['status'] = 'not_checked';
            $row['message'] = 'Konfiguration geändert; erneute Prüfung erforderlich.';
        } elseif ($row['pending'] && ($record['deadline'] ?? 0) <= now()->timestamp) {
            $row['pending'] = false;
            $row['fresh'] = false;
            $row['status'] = 'warning';
            $row['message'] = 'Prüfung abgebrochen oder innerhalb von 120 Sekunden nicht bestätigt.';
        }

        return $row;
    }

    private function normalize(string $id, array $result, int $duration): array
    {
        $row = $this->emptyRow($id);
        $row['status'] = in_array($result['status'] ?? null, ['ok', 'warning', 'error', 'disabled', 'not_configured', 'not_checked', 'running'], true) ? $result['status'] : 'error';
        $row['evidence'] = in_array($result['evidence'] ?? null, ['configuration', 'connection', 'runtime'], true) ? $result['evidence'] : 'configuration';
        $row['message'] = (string) ($result['message'] ?? 'Keine verwertbare Prüfaussage.');
        $row['details'] = array_slice(array_values(array_filter($result['details'] ?? [], 'is_string')), 0, 40);
        $row['checked_at'] = $result['_evidence_at'] ?? now()->toIso8601String();
        $row['duration_ms'] = max(0, $duration);
        $row['source'] = 'live';
        $row['fresh'] = (strtotime($row['checked_at']) ?: 0) > now()->timestamp - self::TTL;
        $row['pending'] = (bool) ($result['pending'] ?? false);

        return $row;
    }

    private function emptyRow(string $id): array
    {
        return $this->registry->get($id) + [
            'status' => 'not_checked', 'evidence' => 'configuration', 'message' => 'Noch nicht geprüft.',
            'details' => [], 'checked_at' => null, 'duration_ms' => null, 'fresh' => false,
            'source' => 'cache', 'run_id' => null, 'pending' => false,
        ];
    }

    private function storageFailure(string $id): array
    {
        $row = $this->emptyRow($id);
        $row['status'] = 'error';
        $row['message'] = 'Der private Diagnosespeicher ist nicht verfügbar.';
        $row['details'] = ['Schreibrechte des privaten Diagnoseverzeichnisses prüfen. Zustandslose Prüfungen bleiben möglich; neue Queueproben sind gesperrt.'];

        return $row;
    }
}
