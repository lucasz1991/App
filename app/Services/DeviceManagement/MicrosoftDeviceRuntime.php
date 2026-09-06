<?php

namespace App\Services\DeviceManagement;

use App\Jobs\ProbeMicrosoftDeviceWorker;
use App\Jobs\SyncMicrosoftDevices;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/** Durable operational evidence. No credentials, device IDs or employee data. */
class MicrosoftDeviceRuntime
{
    private const TABLE = 'microsoft_device_runs';

    private const MESSAGES = [
        'queued' => 'Der Auftrag wartet auf den Microsoft-Queue-Worker.',
        'running' => 'Der Microsoft-Queue-Worker verarbeitet den Auftrag.',
        'success' => 'Der Auftrag wurde erfolgreich abgeschlossen.',
        'partial' => 'Der Geräteabruf wurde mit Klärungsbedarf abgeschlossen.',
        'failed' => 'Der Auftrag konnte nicht abgeschlossen werden.',
        'timeout' => 'Der Worker hat das Zeitlimit überschritten.',
        'queue_lost' => 'Der wartende Auftrag ist nicht mehr in der Datenbankqueue vorhanden.',
        'stale_configuration' => 'Der Auftrag gehört zu einer inzwischen geänderten Konfiguration.',
        'worker_acknowledged' => 'Ein echter Microsoft-Queue-Worker hat den Testauftrag bestätigt.',
    ];

    public function status(): array
    {
        $checks = $this->checks();
        $result = $checks + [
            'scheduler' => ['state' => 'unknown', 'checked_at' => null],
            'worker' => ['state' => 'unknown', 'checked_at' => null],
            'run' => [], 'overdue' => false,
            'worker_probe' => ['status' => 'unknown', 'queued_at' => null, 'acknowledged_at' => null],
        ];
        if (! $this->tableExists(self::TABLE)) {
            return $result;
        }

        try {
            $scheduler = DB::table(self::TABLE)->where('kind', 'scheduler')->first();
            $snapshot = app(MicrosoftDeviceSettings::class)->snapshot();
            $run = DB::table(self::TABLE)->where('kind', 'sync')
                ->where('tenant_id', strtolower((string) ($snapshot['configuration']['tenant_id'] ?? '')))
                ->where('configuration_fingerprint', $snapshot['fingerprint'])->latest('queue_job_id')->first();
            $probe = DB::table(self::TABLE)->where('kind', 'probe')->latest('queue_job_id')->first();
            $active = DB::table(self::TABLE)->whereIn('kind', ['sync', 'probe'])->where('status', 'running')->latest('started_at')->latest('queue_job_id')->first();
            $seen = DB::table(self::TABLE)->whereIn('kind', ['sync', 'probe'])->whereNotNull('started_at')->latest('started_at')->latest('queue_job_id')->first();
            $run = $this->projectQueueLoss($run);
            $probe = $this->projectQueueLoss($probe);
            $active = $this->projectQueueLoss($active);
            $seen = $this->projectQueueLoss($seen);
            if (collect([$run, $probe, $active])->contains(fn (?stdClass $item): bool => $item?->outcome === 'queue_lost')) {
                $result['issues'][] = ['code' => 'queue_lost', 'message' => 'Ein Hintergrundauftrag ist nicht mehr in der Datenbankqueue vorhanden. Der Auftrag kann erneut eingeplant werden.'];
            }
            $checkedAt = $scheduler?->finished_at;
            $result['scheduler'] = ['state' => $checkedAt ? ($this->recent($checkedAt, 600) ? 'fresh' : 'stale') : 'unknown', 'checked_at' => $this->iso($checkedAt)];
            $result['run'] = $run ? $this->runSummary($run) : [];
            $result['overdue'] = ($run && $this->overdue($run)) || ($probe && $this->overdue($probe)) || ($active && $this->overdue($active));
            $workerState = $active && $active->status === 'running' && ! $this->overdue($active) ? 'busy'
                : ($seen && $seen->status === 'failed' ? 'failed' : ($seen && $this->recent($seen->started_at, 900) ? 'seen' : 'unknown'));
            $result['worker'] = ['state' => $workerState, 'checked_at' => $this->iso($seen?->started_at)];
            if ($probe) {
                $result['worker_probe'] = [
                    'status' => $probe->status,
                    'outcome' => $probe->outcome,
                    'queued_at' => $this->iso($probe->queued_at),
                    'acknowledged_at' => $this->iso($probe->acknowledged_at),
                ];
            }
        } catch (Throwable) {
            $result['issues'][] = ['code' => 'runtime_unavailable', 'message' => 'Der Betriebsstatus kann momentan nicht aus der Datenbank gelesen werden.'];
        }

        return $result;
    }

    public function queueSync(array $snapshot, bool $force = false): bool
    {
        $this->assertReady(sync: true);

        return DB::transaction(function () use ($snapshot, $force): bool {
            $setting = Setting::query()->where('type', MicrosoftDeviceSettings::GROUP)
                ->where('key', MicrosoftDeviceSettings::KEY)->lockForUpdate()->first();
            $current = app(MicrosoftDeviceSettings::class)->snapshot();
            $configuration = $current['configuration'];
            if (! $setting || ! ($configuration['enabled'] ?? false)
                || ! Str::isUuid($configuration['tenant_id'] ?? '')
                || ! hash_equals($snapshot['fingerprint'], $current['fingerprint'])) {
                return false;
            }

            return $this->enqueue('sync', strtolower($configuration['tenant_id']), $current['fingerprint'], $force, (int) $configuration['sync_interval_minutes']);
        }, 3);
    }

    public function queueWorkerProbe(): bool
    {
        $this->assertReady(sync: false);

        return $this->enqueue('probe', null, null, true, 0);
    }

    /** Record only from the schedule's explicitly marked command invocation. */
    public function recordSchedulerTick(): void
    {
        if (! $this->tableExists(self::TABLE)) {
            return;
        }
        DB::transaction(function (): void {
            $this->lockDispatch();
            $row = DB::table(self::TABLE)->where('active_key', 'scheduler')->first();
            $values = ['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()];
            if ($row) {
                DB::table(self::TABLE)->where('id', $row->id)->update($values);
            } else {
                DB::table(self::TABLE)->insert($values + ['id' => (string) Str::uuid(), 'kind' => 'scheduler', 'active_key' => 'scheduler', 'created_at' => now()]);
            }
        }, 3);
    }

    /** A direct PHP call cannot acknowledge a worker: a reserved DB job is required. */
    public function claim(string $runId, ?string $queueJobId, string $kind, ?DatabaseJob $databaseJob = null): bool
    {
        if ($queueJobId === null || ! ctype_digit($queueJobId) || $databaseJob === null
            || $databaseJob->getConnectionName() !== SyncMicrosoftDevices::CONNECTION
            || (string) $databaseJob->getJobId() !== $queueJobId) {
            return false;
        }

        return DB::transaction(function () use ($runId, $queueJobId, $kind): bool {
            $run = DB::table(self::TABLE)->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->kind !== $kind || $run->status !== 'queued' || $run->active_key === null
                || (string) $run->queue_job_id !== $queueJobId) {
                return false;
            }
            $job = DB::table('jobs')->where('id', $queueJobId)->where('queue', SyncMicrosoftDevices::QUEUE)->first();
            if (! $job || $job->reserved_at === null || (int) $job->attempts !== 1) {
                return false;
            }
            DB::table(self::TABLE)->where('id', $runId)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

            return true;
        }, 3);
    }

    public function finish(string $runId, string $outcome = 'success', bool $probe = false): void
    {
        $outcome = array_key_exists($outcome, self::MESSAGES) ? $outcome : 'failed';
        $success = in_array($outcome, ['success', 'partial', 'worker_acknowledged'], true);
        $values = [
            'status' => $success ? 'completed' : 'failed', 'outcome' => $outcome,
            'active_key' => null, 'finished_at' => now(), 'updated_at' => now(),
        ];
        if ($probe && $outcome === 'worker_acknowledged') {
            $values['acknowledged_at'] = now();
        }
        DB::table(self::TABLE)->where('id', $runId)->where('status', 'running')->update($values);
    }

    public function fail(string $runId, bool $timeout = false): void
    {
        if (! $this->tableExists(self::TABLE)) {
            return;
        }
        DB::table(self::TABLE)->where('id', $runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'outcome' => $timeout ? 'timeout' : 'failed', 'active_key' => null,
            'finished_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enqueue(string $kind, ?string $tenant, ?string $fingerprint, bool $force, int $interval): bool
    {
        return DB::transaction(function () use ($kind, $tenant, $fingerprint, $force, $interval): bool {
            $this->lockDispatch();
            $key = $kind.':'.($tenant ?? 'worker');
            $active = DB::table(self::TABLE)->where('active_key', $key)->lockForUpdate()->first();
            if ($active) {
                $jobExists = DB::table('jobs')->where('id', $active->queue_job_id)->where('queue', SyncMicrosoftDevices::QUEUE)->exists();
                if ($jobExists && ($active->configuration_fingerprint === $fingerprint || $active->status === 'running')) {
                    return false;
                }
                DB::table(self::TABLE)->where('id', $active->id)->update([
                    'status' => 'failed', 'outcome' => $jobExists ? 'stale_configuration' : 'queue_lost',
                    'active_key' => null, 'finished_at' => now(), 'updated_at' => now(),
                ]);
            }
            $last = DB::table(self::TABLE)->where('kind', $kind)->where('tenant_id', $tenant)
                ->where('configuration_fingerprint', $fingerprint)->latest('queued_at')->first();
            if (! $force && $last && $this->recent($last->queued_at, max(5, $interval) * 60)) {
                return false;
            }

            $id = (string) Str::uuid();
            DB::table(self::TABLE)->insert([
                'id' => $id, 'kind' => $kind, 'active_key' => $key, 'tenant_id' => $tenant,
                'configuration_fingerprint' => $fingerprint, 'status' => 'queued',
                'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $job = $kind === 'probe' ? new ProbeMicrosoftDeviceWorker($id) : new SyncMicrosoftDevices($tenant, $fingerprint, $id);
            // The run and queue row share one DB transaction. Deferring push
            // until commit would recreate an outbox/transport atomicity gap.
            $queueId = Queue::connection(SyncMicrosoftDevices::CONNECTION)->push($job->beforeCommit(), '', SyncMicrosoftDevices::QUEUE);
            if (! is_numeric($queueId) || (int) $queueId < 1
                || ! DB::table('jobs')->where('id', $queueId)->where('queue', SyncMicrosoftDevices::QUEUE)->exists()) {
                throw new RuntimeException('Der Microsoft-Hintergrundauftrag konnte nicht dauerhaft gespeichert werden.');
            }
            DB::table(self::TABLE)->where('id', $id)->update(['queue_job_id' => (int) $queueId]);

            return true;
        }, 3);
    }

    private function lockDispatch(): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::uuid(), 'kind' => 'mutex', 'active_key' => 'dispatch-mutex',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table(self::TABLE)->where('active_key', 'dispatch-mutex')->lockForUpdate()->first();
    }

    private function checks(): array
    {
        $issues = [];
        $schema = true;
        foreach (['devices', 'device_assignments', 'device_account_assignments', 'device_provisioning_profiles', 'device_enrollments', 'device_readiness_checks', 'device_commands', 'employee_identity_accounts', 'microsoft_device_links', self::TABLE] as $table) {
            if (! $this->tableExists($table)) {
                $schema = false;
            }
        }
        try {
            $schema = $schema && Schema::hasColumn('employee_identity_accounts', 'tenant_id');
        } catch (Throwable) {
            $schema = false;
        }
        if (! $schema) {
            $issues[] = ['code' => 'schema_missing', 'message' => 'Für den Geräteimport fehlen Datenbankmigrationen. Bitte die ausstehenden Migrationen ausführen.'];
        }
        $configuration = config('queue.connections.'.SyncMicrosoftDevices::CONNECTION, []);
        $queue = is_array($configuration) && ($configuration['driver'] ?? '') === 'database'
            && ($configuration['connection'] ?? config('database.default')) === config('database.default')
            && ($configuration['table'] ?? '') === 'jobs'
            && (int) ($configuration['retry_after'] ?? 0) > 270
            && $this->tableExists('jobs') && $this->tableExists(self::TABLE);
        if (! $queue) {
            $issues[] = ['code' => 'queue_unavailable', 'message' => 'Die Microsoft-Datenbankqueue oder ihre Tabellen sind nicht bereit. Migrationen und Konfigurationscache prüfen.'];
        }

        return ['schema_ready' => $schema, 'queue_ready' => $queue, 'issues' => $issues];
    }

    private function assertReady(bool $sync): void
    {
        $checks = $this->checks();
        if (! $checks['queue_ready'] || ($sync && ! $checks['schema_ready'])) {
            throw new RuntimeException('Die Microsoft-Geraetesynchronisierung benoetigt die vollstaendige Importmigration und die Datenbankqueue microsoft_devices mit jobs-Tabelle, Standarddatenbank und retry_after ueber 270 Sekunden.');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function runSummary(stdClass $run): array
    {
        return [
            'id' => $run->id, 'status' => $run->status,
            'queued_at' => $this->iso($run->queued_at), 'started_at' => $this->iso($run->started_at),
            'finished_at' => $this->iso($run->finished_at),
            'message' => self::MESSAGES[$run->outcome ?? $run->status] ?? self::MESSAGES['failed'],
        ];
    }

    /** Project lost transport rows without changing state from a GET/poll. */
    private function projectQueueLoss(?stdClass $run): ?stdClass
    {
        if (! $run || ! in_array($run->status, ['queued', 'running'], true) || ! $this->tableExists('jobs')) {
            return $run;
        }
        if (DB::table('jobs')->where('id', $run->queue_job_id)->where('queue', SyncMicrosoftDevices::QUEUE)->exists()) {
            return $run;
        }
        // A worker may have completed between the initial SELECT and transport
        // lookup. Prefer its committed result over a stale queued projection.
        $current = DB::table(self::TABLE)->where('id', $run->id)->first();
        if ($current && ! in_array($current->status, ['queued', 'running'], true)) {
            return $current;
        }
        $projection = clone ($current ?? $run);
        $projection->status = 'failed';
        $projection->outcome = 'queue_lost';

        return $projection;
    }

    private function overdue(stdClass $run): bool
    {
        return ($run->status === 'queued' && ! $this->recent($run->queued_at, 120))
            || ($run->status === 'running' && ! $this->recent($run->started_at, 300));
    }

    private function recent(?string $timestamp, int $seconds): bool
    {
        return $timestamp !== null && CarbonImmutable::parse($timestamp)->gt(now()->subSeconds($seconds));
    }

    private function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->toIso8601String();
    }
}
