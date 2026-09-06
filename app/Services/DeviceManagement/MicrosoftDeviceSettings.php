<?php

namespace App\Services\DeviceManagement;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class MicrosoftDeviceSettings
{
    public const GROUP = 'device_management';

    public const KEY = 'microsoft_graph';

    public const SECRET_MASK = '********';

    private const SECRET_PREFIX = 'enc:v1:';

    private const DEFAULTS = [
        'enabled' => false,
        'tenant_id' => '',
        'client_id' => '',
        'client_secret' => '',
        'intune_enabled' => false,
        'auto_assign' => true,
        'sync_on_sign_in' => true,
        'sync_interval_minutes' => 15,
    ];

    private const STATUS_MESSAGES = [
        'idle' => 'Noch kein Lauf vorhanden.',
        'queued' => 'Die Synchronisierung wurde vorgemerkt.',
        'running' => 'Die Synchronisierung läuft.',
        'success' => 'Der Microsoft-Abruf wurde erfolgreich abgeschlossen.',
        'healthy' => 'Die Microsoft-Verbindung ist bereit.',
        'partial' => 'Der Abruf ist teilweise abgeschlossen. Einige Geräte benötigen eine Prüfung.',
        'failed' => 'Der Microsoft-Abruf konnte nicht abgeschlossen werden.',
        'disabled' => 'Die Microsoft-Gerätesynchronisierung ist deaktiviert.',
        'missing_configuration' => 'Tenant-ID, Client-ID oder Client-Geheimnis fehlen.',
        'invalid_configuration' => 'Die gespeicherte Microsoft-Konfiguration ist ungültig.',
        'unauthorized' => 'Microsoft hat die Anmeldung abgelehnt. Prüfen Sie das Client-Geheimnis.',
        'forbidden' => 'Die erforderlichen Microsoft-Graph-Anwendungsrechte oder die Adminzustimmung fehlen.',
        'unreachable' => 'Microsoft Graph ist momentan nicht erreichbar.',
        'invalid_response' => 'Microsoft Graph lieferte keine verwertbare Antwort.',
        'rate_limited' => 'Microsoft begrenzt derzeit die Abrufe. Der nächste geplante Lauf versucht es erneut.',
        'http_error' => 'Microsoft Graph hat den Abruf nicht erfolgreich beantwortet.',
        'stale_configuration' => 'Die Konfiguration wurde während des Abrufs geändert. Starten Sie den Abruf erneut.',
    ];

    private const COUNTERS = [
        'discovered', 'created', 'updated', 'assigned', 'skipped', 'conflicts', 'entra_devices', 'intune_devices',
    ];

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return $this->configurationFrom($this->stored());
    }

    /**
     * Bind transport credentials and the job/result fingerprint to the same DB
     * read. Calling configuration() and fingerprint() separately can span an
     * administrator's credential or tenant change.
     *
     * @return array{configuration: array<string, mixed>, fingerprint: string}
     */
    public function snapshot(): array
    {
        $configuration = $this->configurationFrom($this->stored());

        return [
            'configuration' => $configuration,
            'fingerprint' => $this->fingerprintFrom($configuration),
        ];
    }

    /** @return array<string, mixed> */
    public function forForm(): array
    {
        $configuration = $this->configuration();
        $configuration['secret_configured'] = $configuration['client_secret'] !== '';
        $configuration['client_secret'] = $configuration['secret_configured'] ? self::SECRET_MASK : '';
        $configuration['clear_client_secret'] = false;

        return $configuration;
    }

    public function fingerprint(): string
    {
        return $this->fingerprintFrom($this->configuration());
    }

    /** @param array<string, mixed> $values */
    public function save(array $values, User $actor): void
    {
        Gate::forUser($actor)->authorize('settings.manage');
        abort_unless($actor->isSuperAdmin(), 403);

        $validated = Validator::make($values, [
            'enabled' => ['sometimes', 'boolean'],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'clear_client_secret' => ['sometimes', 'boolean'],
            'intune_enabled' => ['sometimes', 'boolean'],
            'auto_assign' => ['sometimes', 'boolean'],
            'sync_on_sign_in' => ['sometimes', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'between:5,1440'],
        ], [], [
            'tenant_id' => 'Tenant-ID',
            'client_id' => 'Client-ID',
            'client_secret' => 'Client-Geheimnis',
            'sync_interval_minutes' => 'Synchronisierungsintervall',
        ])->validate();

        DB::transaction(function () use ($validated, $actor): void {
            $setting = $this->lockedSetting();
            $stored = is_array($setting->value) ? $setting->value : [];
            $before = $this->configurationFrom($stored);
            $next = $stored;

            foreach (['enabled', 'intune_enabled', 'auto_assign', 'sync_on_sign_in'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $next[$key] = (bool) $validated[$key];
                }
            }
            foreach (['tenant_id', 'client_id'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $next[$key] = strtolower(trim((string) $validated[$key]));
                }
            }
            if (array_key_exists('sync_interval_minutes', $validated)) {
                $next['sync_interval_minutes'] = (int) $validated['sync_interval_minutes'];
            }

            $targetChanged = ($next['tenant_id'] ?? '') !== $before['tenant_id']
                || ($next['client_id'] ?? '') !== $before['client_id'];
            $submittedSecret = trim((string) ($validated['client_secret'] ?? ''));
            $freshSecret = $submittedSecret !== '' && ! hash_equals(self::SECRET_MASK, $submittedSecret);
            $clearSecret = (bool) ($validated['clear_client_secret'] ?? false);
            if ($clearSecret || ($targetChanged && ! $freshSecret)) {
                $next['client_secret'] = '';
                $next['enabled'] = false;
            } elseif ($freshSecret) {
                $next['client_secret'] = self::SECRET_PREFIX.Crypt::encryptString($submittedSecret);
            }

            $after = $this->configurationFrom($next);
            if (($next['enabled'] ?? false) && ! $after['enabled']) {
                throw ValidationException::withMessages([
                    'client_secret' => 'Für die Aktivierung werden gültige Tenant- und Client-IDs sowie ein entschlüsselbares Client-Geheimnis benötigt.',
                ]);
            }

            $changedFields = [];
            foreach (array_keys(self::DEFAULTS) as $key) {
                if ($before[$key] !== $after[$key]) {
                    $changedFields[] = $key;
                }
            }
            if ($this->fingerprintFrom($before) !== $this->fingerprintFrom($after)) {
                unset($next['last_run'], $next['diagnostic']);
            }
            $setting->forceFill(['value' => $next])->save();

            if ($changedFields !== []) {
                activity('device-management')
                    ->event('microsoft_device_settings_updated')
                    ->causedBy($actor)
                    ->withProperties(['changed_fields' => $changedFields])
                    ->log('Microsoft-Gerätesynchronisierung konfiguriert');
            }
            DB::afterCommit(fn () => Cache::forget('settings.'.self::GROUP.'.'.self::KEY));
        }, 3);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $stored = $this->stored();
        $configuration = $this->configurationFrom($stored);
        $fingerprint = $this->fingerprintFrom($configuration);
        $run = $this->currentSummary($stored['last_run'] ?? null, $fingerprint);
        $diagnostic = $this->currentSummary($stored['diagnostic'] ?? null, $fingerprint);

        return [
            'enabled' => $configuration['enabled'],
            'configured' => $configuration['tenant_id'] !== '' && $configuration['client_id'] !== '' && $configuration['client_secret'] !== '',
            'last_sync_at' => $run['recorded_at'] ?? null,
            'last_diagnostic_at' => $diagnostic['recorded_at'] ?? null,
            'last_run' => $run,
            'diagnostic' => $diagnostic,
        ];
    }

    /** @param array<string, mixed> $summary */
    public function recordRun(array $summary, string $fingerprint): void
    {
        $this->record('last_run', $summary, $fingerprint);
    }

    /** @param array<string, mixed> $summary */
    public function recordDiagnostic(array $summary, string $fingerprint): void
    {
        $this->record('diagnostic', $summary, $fingerprint);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stored = Setting::getValueUncached(self::GROUP, self::KEY);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function configurationFrom(array $stored): array
    {
        $result = self::DEFAULTS;
        foreach (['enabled', 'intune_enabled', 'auto_assign', 'sync_on_sign_in'] as $key) {
            $result[$key] = in_array($stored[$key] ?? $result[$key], [true, 1, '1'], true);
        }
        foreach (['tenant_id', 'client_id'] as $key) {
            $value = is_string($stored[$key] ?? null) ? strtolower(trim($stored[$key])) : '';
            $result[$key] = preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/', $value) ? $value : '';
        }
        $interval = filter_var($stored['sync_interval_minutes'] ?? 15, FILTER_VALIDATE_INT);
        $result['sync_interval_minutes'] = is_int($interval) && $interval >= 5 && $interval <= 1440 ? $interval : 15;
        $ciphertext = $stored['client_secret'] ?? '';
        if (is_string($ciphertext) && str_starts_with($ciphertext, self::SECRET_PREFIX)) {
            try {
                $result['client_secret'] = trim(Crypt::decryptString(substr($ciphertext, strlen(self::SECRET_PREFIX))));
            } catch (Throwable) {
                $result['client_secret'] = '';
            }
        }
        $result['enabled'] = $result['enabled'] && $result['tenant_id'] !== '' && $result['client_id'] !== '' && $result['client_secret'] !== '';

        return $result;
    }

    /** @param array<string, mixed> $configuration */
    private function fingerprintFrom(array $configuration): string
    {
        $configuration['client_secret'] = hash('sha256', $configuration['client_secret']);

        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
    }

    private function lockedSetting(): Setting
    {
        Setting::query()->insertOrIgnore([
            'type' => self::GROUP,
            'key' => self::KEY,
            'value' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Setting::query()->where('type', self::GROUP)->where('key', self::KEY)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $summary */
    private function record(string $key, array $summary, string $fingerprint): void
    {
        DB::transaction(function () use ($key, $summary, $fingerprint): void {
            $setting = $this->lockedSetting();
            $stored = is_array($setting->value) ? $setting->value : [];
            if (! hash_equals($this->fingerprintFrom($this->configurationFrom($stored)), $fingerprint)) {
                return;
            }
            $stored[$key] = $this->sanitizeSummary($summary) + [
                'recorded_at' => now()->toIso8601String(),
                'fingerprint' => $fingerprint,
            ];
            $setting->forceFill(['value' => $stored])->save();
            DB::afterCommit(fn () => Cache::forget('settings.'.self::GROUP.'.'.self::KEY));
        }, 3);
    }

    /** @return array<string, mixed> */
    private function currentSummary(mixed $summary, string $fingerprint): array
    {
        if (! is_array($summary) || ! is_string($summary['fingerprint'] ?? null) || ! hash_equals($fingerprint, $summary['fingerprint'])) {
            return [];
        }
        $recordedAt = $summary['recorded_at'] ?? null;
        if (! is_string($recordedAt) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $recordedAt)) {
            $recordedAt = null;
        }

        return $this->sanitizeSummary($summary) + ['recorded_at' => $recordedAt];
    }

    /** @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function sanitizeSummary(array $summary): array
    {
        $status = is_string($summary['status'] ?? null) && array_key_exists($summary['status'], self::STATUS_MESSAGES)
            ? $summary['status'] : 'failed';
        $safe = ['status' => $status, 'message' => self::STATUS_MESSAGES[$status]];
        foreach (self::COUNTERS as $key) {
            $counter = $summary[$key] ?? null;
            if (is_int($counter) && $counter >= 0 && $counter <= 10000000) {
                $safe[$key] = $counter;
            }
        }
        $intuneStatus = $summary['intune_status'] ?? null;
        if (is_string($intuneStatus) && in_array($intuneStatus, ['success', 'forbidden', 'unauthorized', 'unreachable', 'invalid_response', 'rate_limited', 'http_error'], true)) {
            $safe['intune_status'] = $intuneStatus;
            $safe['intune_message'] = $intuneStatus === 'forbidden'
                ? 'Intune konnte nicht gelesen werden. Prüfen Sie die Intune-Lizenz und das Anwendungsrecht DeviceManagementManagedDevices.Read.All mit Adminzustimmung.'
                : self::STATUS_MESSAGES[$intuneStatus];
        }

        return $safe;
    }
}
