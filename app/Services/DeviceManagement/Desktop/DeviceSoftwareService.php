<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Enums\DeviceLifecycleStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceDesktopClient;
use App\Models\DeviceDesktopJob;
use App\Models\DeviceDesktopSoftwareState;
use App\Models\DeviceSoftwareAssignment;
use App\Models\DeviceSoftwarePackage;
use App\Models\DeviceWorkplace;
use App\Models\User;
use App\Services\DeviceManagement\WorkplaceProfileCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Programmverzeichnis: freigegebene WinGet-Pakete, ihre Zuweisung und der
 * Abgleich mit dem, was ein Desktopclient als installiert meldet.
 *
 * Automatisch entsteht ein Auftrag nur, wenn der globale Aktionsschalter offen
 * ist, die Clientrichtlinie Installationen erlaubt und der Client die
 * Fähigkeit CAPABILITY meldet. Firmengeräte brauchen zusätzlich die Richtlinie
 * `auto_install`; Privatgeräte erhalten nur Pakete, die im bestätigten
 * Arbeitsplatzprofil stehen, und fragen lokal weiter nach.
 */
final class DeviceSoftwareService
{
    public const CAPABILITY = 'managed_software';

    public const MAX_PACKAGES = 30;

    public const MAX_ATTEMPTS = 3;

    /** WinGet-IDs dürfen im Unterschied zu manuellen Einzelaufträgen ein Plus enthalten (Notepad++.Notepad++). */
    public const PACKAGE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_+-]*(?:\.[A-Za-z0-9][A-Za-z0-9_+-]*)+\z/';

    public const REPORTED_VERSION_PATTERN = '/\A[0-9A-Za-z][0-9A-Za-z._+\-]{0,63}\z/';

    /** Wartezeit in Minuten nach dem ersten und dem zweiten Versuch. */
    private const RETRY_MINUTES = [45, 180];

    /** Fehlende oder halb eingespielte Tabellen schalten die Funktion ab, nie den Client-Abruf. */
    public function ready(): bool
    {
        try {
            return Schema::hasTable('device_software_packages') && Schema::hasTable('device_software_assignments')
                && Schema::hasTable('device_desktop_software_states') && Schema::hasColumn('device_desktop_jobs', 'origin');
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertReady(): void
    {
        abort_unless($this->ready(), 503, 'Programmverzeichnis vorübergehend nicht verfügbar. Die freigegebene Datenbankmigration muss durch die IT geprüft werden.');
    }

    public function savePackage(?DeviceSoftwarePackage $package, array $input, User $actor): DeviceSoftwarePackage
    {
        $this->authorizeDirectory($actor);
        $this->assertReady();
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'package_id' => ['required', 'string', 'max:128', 'regex:'.self::PACKAGE_PATTERN],
            'version' => ['required', 'string', 'regex:'.DeviceDesktopService::VERSION_PATTERN],
            'scope' => ['required', 'in:user,machine'],
            'agreements_accepted' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($package, $data, $actor): DeviceSoftwarePackage {
            $actor = $this->authorizeDirectory($actor, lock: true);
            $locked = $package ? DeviceSoftwarePackage::query()->lockForUpdate()->findOrFail($package->id) : new DeviceSoftwarePackage;
            if ($locked->exists && $locked->package_id !== $data['package_id']) {
                throw ValidationException::withMessages(['package_id' => 'Die Paket-ID lässt sich nachträglich nicht ändern. Bitte ein neues Programm anlegen.']);
            }
            if (! $locked->exists && DeviceSoftwarePackage::query()->where('package_id', $data['package_id'])->exists()) {
                throw ValidationException::withMessages(['package_id' => 'Dieses Paket steht bereits im Verzeichnis.']);
            }
            $versionChanged = ! $locked->exists || $locked->version !== $data['version'];
            // Die Bedingungen gelten für genau eine Version; eine neue Version braucht eine neue Bestätigung.
            if ($versionChanged && ($data['agreements_accepted'] ?? false) !== true) {
                throw ValidationException::withMessages(['agreements_accepted' => 'Bitte bestätigen, dass die Lizenz- und Quellbedingungen dieser Version geprüft und akzeptiert sind.']);
            }
            $created = ! $locked->exists;
            $locked->fill(['name' => trim($data['name']), 'package_id' => $data['package_id'], 'version' => $data['version'],
                'scope' => $data['scope'], 'updated_by' => $actor->id]);
            if ($created) {
                $locked->created_by = $actor->id;
            }
            if ($versionChanged) {
                $locked->fill(['agreements_accepted_by' => $actor->id, 'agreements_accepted_at' => now()]);
            }
            $locked->save();
            if ($versionChanged && ! $created) {
                // Eine neue Version bekommt auf jedem Gerät wieder die volle Zahl an Versuchen.
                DeviceDesktopSoftwareState::query()->where('software_package_id', $locked->id)
                    ->update(['attempts' => 0, 'next_attempt_at' => null]);
            }
            $this->audit($locked, $actor, $created ? 'package-created' : 'package-updated',
                ['package_id' => $locked->package_id, 'version' => $locked->version, 'scope' => $locked->scope]);

            return $locked;
        }, 3);
    }

    public function setActive(DeviceSoftwarePackage $package, bool $active, User $actor): void
    {
        $this->authorizeDirectory($actor);
        $this->assertReady();
        DB::transaction(function () use ($package, $active, $actor): void {
            $actor = $this->authorizeDirectory($actor, lock: true);
            $locked = DeviceSoftwarePackage::query()->lockForUpdate()->findOrFail($package->id);
            $locked->forceFill(['active' => $active, 'updated_by' => $actor->id])->save();
            $this->audit($locked, $actor, $active ? 'package-activated' : 'package-deactivated', ['package_id' => $locked->package_id]);
        }, 3);
    }

    /** Ziel: alle Firmengeräte, ein verwaltetes Arbeitsplatzprofil oder ein einzelnes Windows-Gerät. */
    public function assign(DeviceSoftwarePackage $package, string $targetType, ?string $target, User $actor): DeviceSoftwareAssignment
    {
        $this->authorize($actor, 'devices.commands.execute');
        $this->assertReady();

        return DB::transaction(function () use ($package, $targetType, $target, $actor): DeviceSoftwareAssignment {
            $actor = $this->authorize($actor, 'devices.commands.execute', lock: true);
            $locked = DeviceSoftwarePackage::query()->lockForUpdate()->findOrFail($package->id);
            $values = match ($targetType) {
                DeviceSoftwareAssignment::TARGET_CORPORATE => ['target_key' => 'corporate'],
                DeviceSoftwareAssignment::TARGET_PROFILE => $this->profileTarget((string) $target),
                DeviceSoftwareAssignment::TARGET_DEVICE => $this->deviceTarget((string) $target),
                default => throw ValidationException::withMessages(['target' => 'Unbekanntes Ziel für die Zuweisung.']),
            };
            if ($locked->assignments()->where('target_key', $values['target_key'])->exists()) {
                throw ValidationException::withMessages(['target' => 'Dieses Programm ist dem Ziel bereits zugewiesen.']);
            }
            $assignment = $locked->assignments()->create($values + ['target_type' => $targetType, 'created_by' => $actor->id]);
            $this->audit($locked, $actor, 'assigned', ['package_id' => $locked->package_id, 'target' => $values['target_key']]);

            return $assignment;
        }, 3);
    }

    public function unassign(DeviceSoftwareAssignment $assignment, User $actor): void
    {
        $this->authorize($actor, 'devices.commands.execute');
        $this->assertReady();
        DB::transaction(function () use ($assignment, $actor): void {
            $actor = $this->authorize($actor, 'devices.commands.execute', lock: true);
            $locked = DeviceSoftwareAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $package = DeviceSoftwarePackage::query()->findOrFail($locked->software_package_id);
            $locked->delete();
            // Bereits installierte Programme bleiben; es entstehen nur keine neuen Aufträge mehr.
            $this->audit($package, $actor, 'unassigned', ['package_id' => $package->package_id, 'target' => $locked->target_key]);
        }, 3);
    }

    /** Gibt einem Gerät nach ausgeschöpften Versuchen wieder die volle Zahl. */
    public function retry(DeviceDesktopSoftwareState $state, User $actor): void
    {
        $this->authorize($actor, 'devices.commands.execute');
        $this->assertReady();
        DB::transaction(function () use ($state, $actor): void {
            $actor = $this->authorize($actor, 'devices.commands.execute', lock: true);
            $locked = DeviceDesktopSoftwareState::query()->lockForUpdate()->findOrFail($state->id);
            $locked->forceFill(['attempts' => 0, 'next_attempt_at' => null])->save();
            $package = DeviceSoftwarePackage::query()->findOrFail($locked->software_package_id);
            $this->audit($package, $actor, 'retry-released', ['package_id' => $package->package_id, 'client' => $locked->client_id]);
        }, 3);
    }

    /**
     * Pakete, die auf diesem Gerät automatisch vorhanden sein sollen. Leer,
     * solange Richtlinie oder Zustimmung die Automatik nicht tragen.
     *
     * @param  array<string, mixed>  $policy  wirksame Richtlinie aus der Sync-Antwort
     * @return Collection<int, DeviceSoftwarePackage>
     */
    public function plan(Device $device, array $policy): Collection
    {
        if (! $this->ready() || ($policy['allow_software_install'] ?? false) !== true) {
            return collect();
        }
        $workplace = Schema::hasTable('device_workplaces')
            ? DeviceWorkplace::query()->where('device_id', $device->id)->whereNull('revoked_at')->first() : null;
        $corporate = $device->ownership === 'corporate';
        $consented = null;
        if ($corporate) {
            if (($policy['auto_install'] ?? false) !== true) {
                return collect();
            }
        } else {
            $scope = $workplace ? WorkplaceProfileCatalog::scope($workplace->profile_key) : [];
            if (($scope['automatic_programs'] ?? false) !== true) {
                return collect();
            }
            $consented = array_map(fn ($entry) => ($entry['package_id'] ?? '').'@'.($entry['version'] ?? ''), (array) $scope['programs']);
        }
        $targets = ['device:'.$device->id];
        if ($corporate) {
            $targets[] = 'corporate';
        }
        if ($workplace) {
            $targets[] = 'profile:'.$workplace->profile_key;
        }
        $packages = DeviceSoftwarePackage::query()->where('active', true)
            ->whereHas('assignments', fn ($query) => $query->whereIn('target_key', $targets))
            ->orderBy('package_id')->limit(self::MAX_PACKAGES)->get();

        return $consented === null ? $packages
            : $packages->filter(fn (DeviceSoftwarePackage $package) => in_array($package->package_id.'@'.$package->version, $consented, true))->values();
    }

    /**
     * Übernimmt den gemeldeten Stand und merkt für fehlende Pakete einen
     * Auftrag vor. Läuft innerhalb der gesperrten Sync-Transaktion.
     *
     * @param  Collection<int, DeviceSoftwarePackage>  $desired
     * @param  array<int, array{package_id: string, installed: bool, version?: ?string}>  $reported
     */
    public function reconcile(DeviceDesktopClient $client, Collection $desired, array $reported): void
    {
        $packages = $desired->keyBy('package_id');
        foreach ($reported as $entry) {
            $package = $packages->get($entry['package_id']);
            if (! $package) {
                continue; // Gemeldet wird nur, wonach der Server gefragt hat.
            }
            $installed = (bool) $entry['installed'];
            $state = DeviceDesktopSoftwareState::query()->where('client_id', $client->id)
                ->where('software_package_id', $package->id)->lockForUpdate()->first()
                ?? new DeviceDesktopSoftwareState(['client_id' => $client->id, 'software_package_id' => $package->id, 'attempts' => 0]);
            $state->fill(['installed' => $installed, 'reported_version' => $installed ? ($entry['version'] ?? null) : null, 'reported_at' => now()]);
            if ($installed) {
                $state->fill(['attempts' => 0, 'next_attempt_at' => null]);
            }
            $state->save();
            if ($installed || $state->attempts >= self::MAX_ATTEMPTS || $state->next_attempt_at?->isFuture()) {
                continue;
            }
            $open = $client->jobs()->whereIn('status', ['queued', 'offered']);
            if ((clone $open)->where('origin', 'assignment')->where('software_package_id', $package->id)->exists() || $open->count() >= 20) {
                continue;
            }
            $attempt = $state->attempts + 1;
            $job = $client->jobs()->create(['type' => 'install_software', 'origin' => 'assignment',
                'software_package_id' => $package->id, 'attempt' => $attempt, 'requested_by' => null,
                'payload' => ['package_id' => $package->package_id, 'version' => $package->version, 'scope' => $package->scope,
                    'accept_agreements' => $package->agreements_accepted_at !== null],
                'justification' => 'Automatische Installation aus dem Programmverzeichnis: '.$package->name,
                'status' => 'queued', 'expires_at' => now()->addMinutes(30)]);
            $state->forceFill(['attempts' => $attempt,
                'next_attempt_at' => now()->addMinutes(self::RETRY_MINUTES[$attempt - 1] ?? 180)])->save();
            activity('device-management')->performedOn($client)->event('device-software.job-queued')
                ->withProperties(['client_id' => $client->public_id, 'job_id' => $job->public_id,
                    'package_id' => $package->package_id, 'version' => $package->version, 'attempt' => $attempt])
                ->log('Programmverzeichnis job-queued');
        }
    }

    /** Ein Auftrag aus dem Verzeichnis gilt nur, solange Paket, Version und Zuweisung unverändert tragen. */
    public function covers(Collection $desired, DeviceDesktopJob $job): bool
    {
        $package = $desired->firstWhere('id', $job->software_package_id);

        return $package !== null && ($job->payload['version'] ?? null) === $package->version
            && ($job->payload['package_id'] ?? null) === $package->package_id;
    }

    private function profileTarget(string $key): array
    {
        if (! (WorkplaceProfileCatalog::all()[$key]['managed'] ?? false)) {
            throw ValidationException::withMessages(['target' => 'Nur verwaltete Arbeitsplatzprofile können Programme erhalten.']);
        }

        return ['profile_key' => $key, 'target_key' => 'profile:'.$key];
    }

    private function deviceTarget(string $publicId): array
    {
        $device = Device::query()->where('public_id', $publicId)->first();
        $metadata = $device?->metadata ?? [];
        $endpoint = $device && $device->platform === DevicePlatform::Windows
            && in_array($device->ownership, ['corporate', 'byod'], true)
            && ! in_array($device->lifecycle_status, [DeviceLifecycleStatus::Lost, DeviceLifecycleStatus::Retired], true)
            && ($metadata['controller_only'] ?? false) !== true && ($metadata['desktop_controller_only'] ?? false) !== true
            && ($metadata['management_role'] ?? '') !== 'controller';
        if (! $endpoint) {
            throw ValidationException::withMessages(['target' => 'Nur verfügbare Windows-Endgeräte können Programme erhalten; Verwaltungsstationen nicht.']);
        }

        return ['device_id' => $device->id, 'target_key' => 'device:'.$device->id];
    }

    private function authorizeDirectory(User $actor, bool $lock = false): User
    {
        $fresh = User::query()->whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($fresh?->isActive() && $fresh->isSuperAdmin() && $fresh->email_verified_at !== null, 403);
        Gate::forUser($fresh)->authorize('settings.manage');

        return $fresh;
    }

    private function authorize(User $actor, string $permission, bool $lock = false): User
    {
        $fresh = User::query()->whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($fresh?->isActive(), 403);
        Gate::forUser($fresh)->authorize($permission);

        return $fresh;
    }

    private function audit(Model $subject, User $actor, string $event, array $properties): void
    {
        activity('device-management')->performedOn($subject)->causedBy($actor)->event('device-software.'.$event)
            ->withProperties($properties)->log('Programmverzeichnis '.$event);
    }
}
