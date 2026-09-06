<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Jobs\SyncMicrosoftDevices;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** The interactive Microsoft request only schedules work; Graph runs in a worker. */
final class MicrosoftDeviceSyncScheduler
{
    public const RESERVATION_SECONDS = 900;

    public function __construct(
        private readonly MicrosoftDeviceSettings $settings,
    ) {}

    public function queue(bool $force = false): bool
    {
        $snapshot = $this->settings->snapshot();
        $configuration = $snapshot['configuration'];
        if (! ($configuration['enabled'] ?? false)) {
            return false;
        }

        $tenantId = strtolower(trim((string) ($configuration['tenant_id'] ?? '')));
        if (! Str::isUuid($tenantId)) {
            throw new RuntimeException('Bitte zuerst eine gueltige Microsoft-Entra-Tenant-ID speichern.');
        }

        $this->assertQueueReady();

        $fingerprint = $snapshot['fingerprint'];
        $dispatch = function () use ($tenantId, $fingerprint, $force): bool {
            // An enclosing transaction may have changed configuration before commit.
            $currentSnapshot = $this->settings->snapshot();
            $current = $currentSnapshot['configuration'];
            if (! ($current['enabled'] ?? false)
                || ! hash_equals($fingerprint, $currentSnapshot['fingerprint'])
                || ! hash_equals($tenantId, strtolower((string) ($current['tenant_id'] ?? '')))) {
                return false;
            }

            return (bool) Cache::lock($this->key($tenantId, 'dispatch'), 10)->get(function () use ($tenantId, $fingerprint, $force, $current): bool {
                if (Cache::has($this->key($tenantId, 'pending'))) {
                    return false;
                }

                $interval = max(5, min(1440, (int) ($current['sync_interval_minutes'] ?? 15)));
                $last = Cache::get($this->key($tenantId, 'last_dispatch'), []);
                if (! $force && is_array($last)
                    && ($last['fingerprint'] ?? '') === $fingerprint
                    && (int) ($last['at'] ?? 0) > now()->timestamp - ($interval * 60)) {
                    return false;
                }

                $reservation = (string) Str::uuid();
                Cache::put($this->key($tenantId, 'pending'), $reservation, self::RESERVATION_SECONDS);

                try {
                    Bus::dispatch(new SyncMicrosoftDevices($tenantId, $fingerprint, $reservation));
                } catch (Throwable $exception) {
                    Cache::forget($this->key($tenantId, 'pending'));
                    throw $exception;
                }

                Cache::put($this->key($tenantId, 'last_dispatch'), [
                    'at' => now()->timestamp,
                    'fingerprint' => $fingerprint,
                ], now()->addDays(2));

                return true;
            });
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($dispatch): void {
                try {
                    $dispatch();
                } catch (Throwable) {
                    $this->logDispatchFailure();
                }
            });

            return true;
        }

        return $dispatch();
    }

    public function afterMicrosoftSignIn(VerifiedEntraIdentity $identity, User $user): void
    {
        try {
            $configuration = $this->settings->snapshot()['configuration'];
            if (! ($configuration['enabled'] ?? false)
                || ! ($configuration['sync_on_sign_in'] ?? false)
                || ! hash_equals(strtolower((string) ($configuration['tenant_id'] ?? '')), strtolower($identity->tenantId))
                || ! Str::isUuid($identity->tenantId)
                || ! Str::isUuid($identity->objectId)
                || ! Schema::hasColumn('employee_identity_accounts', 'tenant_id')) {
                return;
            }

            $currentUser = $user->fresh();
            if (! $currentUser?->isActive() || $currentUser->email_verified_at === null) {
                return;
            }

            DB::transaction(function () use ($identity, $currentUser): void {
                $setting = Setting::query()->where('type', MicrosoftDeviceSettings::GROUP)
                    ->where('key', MicrosoftDeviceSettings::KEY)->lockForUpdate()->first();
                $current = $this->settings->snapshot()['configuration'];
                if (! $setting || ! ($current['enabled'] ?? false)
                    || ! ($current['sync_on_sign_in'] ?? false)
                    || ! hash_equals(strtolower($identity->tenantId), strtolower((string) ($current['tenant_id'] ?? '')))) {
                    return;
                }

                $account = EmployeeIdentityAccount::query()
                    ->forProvider(AccountProvider::Microsoft365)
                    ->active()
                    ->where('external_id', strtolower($identity->objectId))
                    ->where('user_id', $currentUser->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    return;
                }

                $lockedUser = User::query()->whereKey($currentUser->getKey())->lockForUpdate()->first();
                if (! $lockedUser?->isActive() || $lockedUser->email_verified_at === null) {
                    return;
                }

                $addresses = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), [
                    $account->principal, $account->email,
                ]);
                if (! in_array(strtolower(trim($identity->principal)), $addresses, true)) {
                    return;
                }

                $tenantId = strtolower($identity->tenantId);
                if ($account->tenant_id === null) {
                    // Existing pre-provisioned accounts predate the tenant column.
                    // Only the verified, same configured Add-in tenant may bind them.
                    if (! hash_equals($tenantId, strtolower((string) config('outlook_addin.entra.tenant_id', '')))) {
                        return;
                    }

                    $account->forceFill(['tenant_id' => $tenantId])->save();

                } elseif (! hash_equals($tenantId, strtolower((string) $account->tenant_id))) {
                    return;
                }

                // Capture the queued snapshot while the settings row is locked.
                // Dispatch happens after commit and rechecks that same fingerprint.
                // Cached compose refreshes still respect the normal interval.
                $this->queue();
            });
        } catch (Throwable) {
            $this->logDispatchFailure();
        }
    }

    public function release(string $tenantId, string $reservation): void
    {
        Cache::lock($this->key($tenantId, 'dispatch'), 10)->get(function () use ($tenantId, $reservation): void {
            if (Cache::get($this->key($tenantId, 'pending')) === $reservation) {
                Cache::forget($this->key($tenantId, 'pending'));
            }
        });
    }

    private function key(string $tenantId, string $suffix): string
    {
        return 'microsoft-device-sync:'.hash('sha256', strtolower($tenantId)).':'.$suffix;
    }

    private function assertQueueReady(): void
    {
        $configuration = config('queue.connections.'.SyncMicrosoftDevices::CONNECTION, []);
        if (! is_array($configuration)
            || ($configuration['driver'] ?? '') !== 'database'
            || (int) ($configuration['retry_after'] ?? 0) <= 270) {
            throw new RuntimeException('Die Microsoft-Geraetesynchronisierung benoetigt die Datenbankqueue microsoft_devices mit retry_after ueber 270 Sekunden. Bitte den Konfigurationscache nach dem Update erneuern.');
        }

        try {
            $database = $configuration['connection'] ?? config('database.default');
            $hasTable = Schema::connection($database)->hasTable((string) ($configuration['table'] ?? 'jobs'));
        } catch (Throwable) {
            $hasTable = false;
        }

        if (! $hasTable) {
            throw new RuntimeException('Die Microsoft-Geraetesynchronisierung benoetigt die erreichbare jobs-Tabelle. Bitte die Datenbankverbindung und ausstehenden Migrationen pruefen.');
        }
    }

    private function logDispatchFailure(): void
    {
        // Queue exceptions can contain connection credentials or serialized payloads.
        Log::warning('Microsoft-Geraetesync konnte nicht eingeplant werden. Asynchrone Geraete-Queue und Einstellungen pruefen.', [
            'error_code' => 'microsoft_device_sync_dispatch_failed',
        ]);
    }
}
