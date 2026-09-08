<?php

namespace App\Services\DeviceManagement;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Opt-in for exact, administrator-reviewed employee IDs, never tenant discovery. */
final class MicrosoftEmployeeImportSettings
{
    public const GROUP = 'device_management';

    public const KEY = 'microsoft_employee_import';

    public const MAX_PILOT_USERS = 20;

    public const UUID_PATTERN = '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/';

    private const MESSAGES = [
        'preview' => 'Vorschau abgeschlossen. Es wurden keine Mitarbeiter angelegt oder verändert.',
        'success' => 'Der Pilotimport wurde abgeschlossen. Neue Mitarbeiter bleiben inaktiv.',
        'partial' => 'Der Pilot wurde geprüft; Konflikte oder ausgeschlossene Konten benötigen eine manuelle Prüfung.',
        'disabled' => 'Der separate Mitarbeiter-Pilotimport ist deaktiviert.',
        'missing_configuration' => 'Microsoft-Zugangsdaten oder die explizite Pilotfreigabe fehlen.',
        'invalid_configuration' => 'Die gespeicherte Pilotkonfiguration ist ungültig.',
        'stale_configuration' => 'Die Konfiguration hat sich während des Abrufs geändert. Es wurde nichts importiert.',
        'forbidden' => 'Microsoft-Graph-Anwendungsrecht User.Read.All mit Adminzustimmung fehlt oder der Zugriff wurde verweigert.',
        'unauthorized' => 'Microsoft hat die Anmeldung abgelehnt. Prüfen Sie die gespeicherten Zugangsdaten.',
        'unreachable' => 'Microsoft Graph ist momentan nicht erreichbar.',
        'invalid_response' => 'Microsoft Graph lieferte keine vollständige, gültige Pilotantwort.',
        'rate_limited' => 'Microsoft begrenzt die Abrufe. Bitte später erneut versuchen.',
        'request_limit' => 'Das kurze Zeit- oder Anfragebudget wurde erreicht. Der gesamte Import wurde ohne Mitarbeiterschreibvorgänge abgebrochen.',
        'response_limit' => 'Die Microsoft-Antwort überschreitet die erlaubte Größe. Es wurde nichts importiert.',
        'http_error' => 'Ein Pilotkonto konnte nicht gelesen werden. Prüfen Sie Objekt-IDs und Microsoft-Freigaben.',
        'failed' => 'Der Mitarbeiter-Pilotimport konnte nicht abgeschlossen werden.',
    ];

    private const COUNTERS = ['requested', 'eligible', 'created', 'would_create', 'existing', 'conflicts', 'skipped'];

    public function __construct(private readonly MicrosoftDeviceSettings $microsoft) {}

    public function configuration(): array
    {
        return $this->snapshot()['configuration'];
    }

    public function forForm(): array
    {
        return $this->configuration();
    }

    /** graph_configuration contains secrets and must never be returned to a browser or log. */
    public function snapshot(): array
    {
        $stored = $this->stored();
        $configuration = $this->configurationFrom($stored);
        $graph = $this->microsoft->snapshot();
        // A scope reviewed for one tenant cannot silently follow a credential
        // target change. The administrator must save the pilot scope again.
        $configuration['enabled'] = $configuration['enabled']
            && is_string($stored['approved_tenant_id'] ?? null)
            && $stored['approved_tenant_id'] !== ''
            && hash_equals($stored['approved_tenant_id'], $graph['configuration']['tenant_id']);

        return [
            'configuration' => $configuration,
            'graph_configuration' => $graph['configuration'],
            'fingerprint' => hash('sha256', json_encode([
                'import' => $configuration,
                'microsoft' => $graph['fingerprint'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public function fingerprint(): string
    {
        return $this->snapshot()['fingerprint'];
    }

    public function save(array $values, User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor !== null && $actor->isSuperAdmin() && $actor->isActive(), 403);
        Gate::forUser($actor)->authorize('settings.manage');
        Gate::forUser($actor)->authorize('employees.create');

        $validated = Validator::make($values, [
            'enabled' => ['required', 'boolean'],
            'pilot_object_ids' => ['present', 'array', 'max:'.self::MAX_PILOT_USERS],
            'pilot_object_ids.*' => ['required', 'string', 'regex:'.self::UUID_PATTERN, 'distinct:strict'],
        ], [], ['pilot_object_ids' => 'Pilot-Objekt-IDs'])->validate();
        sort($validated['pilot_object_ids'], SORT_STRING);
        $validated['enabled'] = (bool) $validated['enabled'];

        DB::transaction(function () use ($validated, $actor): void {
            $this->lockConfiguration();
            $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            abort_unless($actor !== null && $actor->isSuperAdmin() && $actor->isActive(), 403);
            Gate::forUser($actor)->authorize('settings.manage');
            Gate::forUser($actor)->authorize('employees.create');
            $graph = $this->microsoft->snapshot()['configuration'];
            if ($validated['enabled'] && ($validated['pilot_object_ids'] === []
                || $graph['tenant_id'] === '' || $graph['client_id'] === '' || $graph['client_secret'] === '')) {
                throw ValidationException::withMessages([
                    'enabled' => 'Zur Aktivierung werden gespeicherte Microsoft-Zugangsdaten und mindestens eine ausdrücklich freigegebene Mitarbeiter-Objekt-ID benötigt.',
                ]);
            }

            $setting = Setting::query()->where('type', self::GROUP)->where('key', self::KEY)->firstOrFail();
            $before = $this->configurationFrom((array) $setting->value);
            if ($before === $validated && ($setting->value['approved_tenant_id'] ?? null) === $graph['tenant_id']) {
                return;
            }
            // Result evidence belongs to the exact prior scope and is invalidated.
            $setting->forceFill(['value' => $validated + ['approved_tenant_id' => $graph['tenant_id']]])->save();
            activity('device-management')->causedBy($actor)->event('microsoft_employee_import_settings_updated')
                ->withProperties(['enabled' => $validated['enabled'], 'pilot_count' => count($validated['pilot_object_ids'])])
                ->log('Mitarbeiter-Pilotfreigabe aktualisiert');
            DB::afterCommit(fn () => Cache::forget('settings.'.self::GROUP.'.'.self::KEY));
        }, 3);
    }

    /** Called inside a transaction, always Microsoft credentials first, import scope second. */
    public function lockConfiguration(): void
    {
        Setting::query()->where('type', MicrosoftDeviceSettings::GROUP)
            ->where('key', MicrosoftDeviceSettings::KEY)->lockForUpdate()->first();
        Setting::query()->insertOrIgnore([
            'type' => self::GROUP, 'key' => self::KEY, 'value' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Setting::query()->where('type', self::GROUP)->where('key', self::KEY)->lockForUpdate()->firstOrFail();
    }

    public function status(): array
    {
        $stored = $this->stored();
        $snapshot = $this->snapshot();
        $last = $stored['last_result'] ?? null;
        $valid = is_array($last) && is_string($last['fingerprint'] ?? null)
            && hash_equals($snapshot['fingerprint'], $last['fingerprint']);
        $recordedAt = $valid ? ($last['recorded_at'] ?? null) : null;
        if (! is_string($recordedAt) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $recordedAt)) {
            $valid = false;
        }

        return [
            'enabled' => $snapshot['configuration']['enabled'],
            'pilot_count' => count($snapshot['configuration']['pilot_object_ids']),
            'last_result' => $valid ? $this->sanitize($last) + ['recorded_at' => $recordedAt] : [],
        ];
    }

    public function recordResult(array $summary, string $fingerprint): void
    {
        DB::transaction(function () use ($summary, $fingerprint): void {
            $this->lockConfiguration();
            if (! hash_equals($this->fingerprint(), $fingerprint)) {
                return;
            }
            $setting = Setting::query()->where('type', self::GROUP)->where('key', self::KEY)->firstOrFail();
            $stored = (array) $setting->value;
            $stored['last_result'] = $this->sanitize($summary) + [
                'recorded_at' => now()->toIso8601String(), 'fingerprint' => $fingerprint,
            ];
            $setting->forceFill(['value' => $stored])->save();
            DB::afterCommit(fn () => Cache::forget('settings.'.self::GROUP.'.'.self::KEY));
        }, 3);
    }

    public function sanitize(array $summary): array
    {
        $status = is_string($summary['status'] ?? null) && array_key_exists($summary['status'], self::MESSAGES)
            ? $summary['status'] : 'failed';
        $safe = ['status' => $status, 'message' => self::MESSAGES[$status],
            'mode' => ($summary['mode'] ?? null) === 'import' ? 'import' : 'preview'];
        foreach (self::COUNTERS as $counter) {
            $value = $summary[$counter] ?? null;
            $safe[$counter] = is_int($value) && $value >= 0 && $value <= self::MAX_PILOT_USERS ? $value : 0;
        }

        return $safe;
    }

    private function stored(): array
    {
        $stored = Setting::getValueUncached(self::GROUP, self::KEY);

        return is_array($stored) ? $stored : [];
    }

    private function configurationFrom(array $stored): array
    {
        $ids = $stored['pilot_object_ids'] ?? [];
        $valid = is_array($ids) && array_is_list($ids) && count($ids) <= self::MAX_PILOT_USERS;
        if ($valid) {
            foreach ($ids as $id) {
                if (! is_string($id) || ! preg_match(self::UUID_PATTERN, $id)) {
                    $valid = false;
                    break;
                }
            }
            $valid = $valid && count(array_unique($ids)) === count($ids);
        }
        $ids = $valid ? $ids : [];
        sort($ids, SORT_STRING);

        return ['enabled' => $valid && $ids !== [] && ($stored['enabled'] ?? false) === true, 'pilot_object_ids' => $ids];
    }
}
