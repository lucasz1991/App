<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * A manually reviewed, exact-ID pilot. This does not activate users, authenticate
 * browsers, enroll endpoints, verify email addresses or install any applications.
 */
class MicrosoftEmployeeImportService
{
    public function __construct(
        private readonly MicrosoftEmployeeImportSettings $settings,
        private readonly MicrosoftGraphDeviceClient $graph,
    ) {}

    public function preview(?string $expectedFingerprint = null, ?User $actor = null): array
    {
        return $this->run(false, $expectedFingerprint, $actor);
    }

    public function import(?string $expectedFingerprint = null, ?User $actor = null): array
    {
        return $this->run(true, $expectedFingerprint, $actor);
    }

    private function run(bool $apply, ?string $expectedFingerprint, ?User $actor): array
    {
        // Console entry is a local operator action, not a public authorization path.
        $actor ??= app()->runningInConsole() ? null : auth()->user();
        if ($actor !== null) {
            $actor = $actor->fresh();
            abort_unless($actor !== null && $actor->isSuperAdmin() && $actor->isActive(), 403);
            Gate::forUser($actor)->authorize('settings.manage');
            Gate::forUser($actor)->authorize('employees.create');
        } elseif (! app()->runningInConsole()) {
            abort(403);
        }

        $snapshot = $this->settings->snapshot();
        $fingerprint = $snapshot['fingerprint'];
        $configuration = $snapshot['configuration'];
        $summary = $this->settings->sanitize(['status' => 'failed', 'mode' => $apply ? 'import' : 'preview']);
        $summary['requested'] = count($configuration['pilot_object_ids']);

        try {
            if ($expectedFingerprint !== null && ! hash_equals($fingerprint, $expectedFingerprint)) {
                throw new MicrosoftGraphDeviceException('stale_configuration');
            }
            if (! $configuration['enabled']) {
                throw new MicrosoftGraphDeviceException('disabled');
            }
            if (! Schema::hasTable('user_profiles') || ! Schema::hasColumn('employee_identity_accounts', 'tenant_id')) {
                throw new MicrosoftGraphDeviceException('invalid_configuration');
            }
            $tenantId = $snapshot['graph_configuration']['tenant_id'];
            $this->graph->beginEmployeeImport($snapshot['graph_configuration']);
            $records = [];
            foreach ($configuration['pilot_object_ids'] as $objectId) {
                $record = $this->normalize($this->graph->user($objectId), $objectId);
                if ($record === null) {
                    $summary['skipped']++;
                } else {
                    $records[] = $record;
                }
            }
            $summary['eligible'] = count($records);

            // Read every selected object before any employee write. A timeout,
            // missing permission, 404 or malformed later response imports none.
            $summary = DB::transaction(function () use ($records, $tenantId, $fingerprint, $summary, $apply, $actor): array {
                $this->settings->lockConfiguration();
                if (! hash_equals($this->settings->fingerprint(), $fingerprint)) {
                    throw new MicrosoftGraphDeviceException('stale_configuration');
                }
                if ($actor !== null) {
                    $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
                    abort_unless($actor !== null && $actor->isSuperAdmin() && $actor->isActive(), 403);
                    Gate::forUser($actor)->authorize('settings.manage');
                    Gate::forUser($actor)->authorize('employees.create');
                }
                // User::isSuperAdmin() is based on ID 1, not its role. A pilot
                // must never bootstrap the system's first user accidentally.
                if (! User::query()->whereKey(1)->lockForUpdate()->exists()) {
                    throw new MicrosoftGraphDeviceException('invalid_configuration');
                }

                $duplicateAddresses = $this->duplicateAddresses($records);
                foreach ($records as $record) {
                    if (array_intersect([$record['principal'], $record['email']], $duplicateAddresses) !== []) {
                        $summary['conflicts']++;

                        continue;
                    }
                    $state = $this->existingState($record, $tenantId);
                    if ($state !== 'new') {
                        $summary[$state]++;

                        continue;
                    }
                    if (! $apply) {
                        $summary['would_create']++;

                        continue;
                    }
                    $user = User::withoutEvents(fn (): User => User::query()->create([
                        'name' => $record['name'],
                        'email' => $record['email'],
                        'locale' => (string) config('app.locale', 'de'),
                        'role' => 'staff',
                        'status' => false,
                        // Required local hash, never the Microsoft password and
                        // never communicated or usable as a known default.
                        'password' => Hash::make(Str::random(64)),
                    ]));
                    if ($user->isSuperAdmin()) {
                        throw new MicrosoftGraphDeviceException('invalid_configuration');
                    }
                    UserProfile::withoutEvents(fn () => UserProfile::query()->create([
                        'user_id' => $user->getKey(),
                        'first_name' => $record['first_name'],
                        'last_name' => $record['last_name'],
                    ]));
                    EmployeeIdentityAccount::query()->create([
                        'user_id' => $user->getKey(),
                        'provider' => AccountProvider::Microsoft365,
                        'tenant_id' => $tenantId,
                        'external_id' => $record['object_id'],
                        'principal' => $record['principal'],
                        'email' => $record['email'],
                        // Directory account is enabled; RailTime user stays
                        // independently inactive until an administrator acts.
                        'lifecycle_status' => 'active',
                        'provisioning_status' => 'pending_provider',
                        'license_status' => 'unknown',
                        'last_synced_at' => now(),
                        'metadata' => ['source' => 'microsoft_employee_pilot', 'imported_at' => now()->toIso8601String()],
                    ]);
                    $audit = activity('device-management')->performedOn($user)
                        ->event('microsoft_employee_imported_inactive')
                        ->withProperties(['source' => 'microsoft_employee_pilot', 'status' => 'inactive']);
                    if ($actor !== null) {
                        $audit->causedBy($actor);
                    }
                    $audit->log('Mitarbeiter aus expliziter Microsoft-Pilotfreigabe inaktiv angelegt');
                    $summary['created']++;
                }
                $summary['status'] = $summary['conflicts'] > 0 || $summary['skipped'] > 0
                    ? 'partial' : ($apply ? 'success' : 'preview');

                return $summary;
            }, 3);
        } catch (MicrosoftGraphDeviceException $exception) {
            $summary['status'] = $exception->reason;
            $summary['created'] = 0;
            $summary['would_create'] = 0;
        } catch (Throwable) {
            // Never serialize a DB/HTTP exception or response containing PII,
            // credentials or partially executed SQL into settings or the UI.
            $summary['status'] = 'failed';
            $summary['created'] = 0;
            $summary['would_create'] = 0;
        }

        $summary = $this->settings->sanitize($summary);
        $this->settings->recordResult($summary, $fingerprint);

        return $summary;
    }

    /** Eligible Member accounts must also be explicitly selected by their administrator. */
    private function normalize(array $record, string $expectedId): ?array
    {
        if (($record['id'] ?? null) !== $expectedId
            || ! in_array($record['userType'] ?? null, ['Member', 'Guest'], true)
            || ! is_bool($record['accountEnabled'] ?? null)
            || isset($record['@odata.nextLink'])) {
            throw new MicrosoftGraphDeviceException('invalid_response');
        }
        if ($record['userType'] !== 'Member' || $record['accountEnabled'] !== true) {
            return null;
        }
        $principal = $this->address($record['userPrincipalName'] ?? null);
        $email = isset($record['mail']) && $record['mail'] !== '' ? $this->address($record['mail']) : $principal;
        $name = $this->text($record['displayName'] ?? null, required: true);

        return [
            'object_id' => $expectedId, 'principal' => $principal, 'email' => $email, 'name' => $name,
            'first_name' => $this->text($record['givenName'] ?? null),
            'last_name' => $this->text($record['surname'] ?? null),
        ];
    }

    private function existingState(array $record, string $tenantId): string
    {
        $addresses = array_values(array_unique([$record['principal'], $record['email']]));
        $identities = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)
            ->where(function ($query) use ($record, $addresses): void {
                $query->whereRaw('LOWER(external_id) = ?', [$record['object_id']])
                    ->orWhereIn(DB::raw('LOWER(principal)'), $addresses)
                    ->orWhereIn(DB::raw('LOWER(email)'), $addresses);
            })->lockForUpdate()->get();

        if ($identities->isNotEmpty()) {
            $identity = $identities->first();
            $user = $identity->user_id ? User::query()->whereKey($identity->user_id)->lockForUpdate()->first() : null;
            if ($identities->count() === 1 && $user !== null && ! $user->isSuperAdmin()
                && $identity->tenant_id === $tenantId && $identity->external_id === $record['object_id']
                && $identity->principal === $record['principal'] && $identity->email === $record['email']
                && $identity->lifecycle_status === 'active') {
                return 'existing';
            }

            return 'conflicts';
        }
        // An email collision is not identity proof. Never silently attach an
        // imported directory identity to a local/manual account, even inactive.
        if (User::query()->whereIn(DB::raw('LOWER(email)'), $addresses)->lockForUpdate()->exists()) {
            return 'conflicts';
        }

        return 'new';
    }

    private function duplicateAddresses(array $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            foreach (array_unique([$record['principal'], $record['email']]) as $address) {
                $counts[$address] = ($counts[$address] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
    }

    private function address(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 191
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new MicrosoftGraphDeviceException('invalid_response');
        }

        return strtolower($value);
    }

    private function text(mixed $value, bool $required = false): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)
            || ($required && trim($value) === '')) {
            throw new MicrosoftGraphDeviceException('invalid_response');
        }

        return trim($value) === '' ? null : trim($value);
    }
}
