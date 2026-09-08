<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Enums\DeviceLifecycleStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopClient;
use App\Models\DeviceDesktopEnrollment;
use App\Models\DeviceDesktopJob;
use App\Models\User;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\Support\SafeProviderData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeviceDesktopService
{
    public const VERSION_PATTERN = '/\A[0-9A-Za-z][0-9A-Za-z._+\-]{0,39}\z/';

    public const PACKAGE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+\z/';

    public const POLICY_KEYS = ['allow_camera', 'allow_location', 'allow_notifications', 'allow_software_install', 'kiosk_enabled'];

    public function __construct(private readonly DeviceManagementSettings $settings) {}

    /** Missing/partially deployed storage is maintenance, never auto-migrated. */
    public function assertStorageReady(): void
    {
        $ready = true;
        try {
            foreach (['device_desktop_clients', 'device_desktop_enrollments', 'device_desktop_jobs'] as $table) {
                if (! Schema::hasTable($table)) {
                    $ready = false;
                    break;
                }
            }
        } catch (\Throwable) {
            // Never forward connection strings, query bindings or raw exceptions.
            $ready = false;
        }
        abort_unless($ready, 503, 'Desktopclient-Verwaltung vorübergehend nicht verfügbar. Die freigegebene Datenbankmigration muss durch die IT geprüft werden.');
    }

    public function defaults(): array
    {
        return ['allow_camera' => false, 'allow_location' => false, 'allow_notifications' => true,
            'allow_software_install' => false, 'kiosk_enabled' => false];
    }

    /** Returns the one-time enrollment secret only to the issuing administrator. */
    public function issue(Device $device, User $actor, bool $endpointConfirmed): array
    {
        $this->authorize($actor, 'devices.enrollment.manage');
        $this->assertStorageReady();
        if (! $endpointConfirmed) {
            throw ValidationException::withMessages(['endpointConfirmed' => 'Ein Mitarbeiter-Endgerät muss ausdrücklich bestätigt werden; der Administrator-Haupt-PC bleibt nur Verwaltungsstation.']);
        }

        return DB::transaction(function () use ($device, $actor): array {
            [$locked, $assignment] = $this->context($device->getKey());
            $this->authorize($actor, 'devices.enrollment.manage');
            if (DeviceDesktopClient::query()->where('device_id', $locked->id)->whereIn('status', ['active', 'unpaired'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['device' => 'Ein Client ist bereits verbunden oder eingeladen. Bitte diesen zuerst ausdrücklich widerrufen.']);
            }
            $client = DeviceDesktopClient::query()->create([
                'device_id' => $locked->id, 'device_assignment_id' => $assignment->id,
                'status' => 'unpaired', 'policy' => $this->defaults(), 'created_by' => $actor->id,
            ]);
            $token = $this->token('rtep_');
            $enrollment = DeviceDesktopEnrollment::query()->create([
                'client_id' => $client->id, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(15),
            ]);
            $this->audit($client, $actor, 'pairing-issued');

            return ['client' => $client, 'enrollment_token' => $token, 'expires_at' => $enrollment->expires_at->toIso8601String()];
        }, 3);
    }

    public function enroll(#[\SensitiveParameter] array $input): array
    {
        $this->assertStorageReady();
        $data = Validator::make($input, [
            'enrollment_token' => ['required', 'string', 'regex:/\Artep_[A-Za-z0-9_-]{43}\z/'],
            'client_instance_id' => ['required', 'uuid'],
            'client_version' => ['required', 'string', 'regex:'.self::VERSION_PATTERN],
            'platform' => ['required', 'in:windows'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
        ])->validate();
        $snapshot = DeviceDesktopEnrollment::query()->where('token_hash', hash('sha256', $data['enrollment_token']))->first();
        abort_unless($snapshot !== null, 401, 'Registrierung nicht gültig.');
        $clientSnapshot = DeviceDesktopClient::query()->find($snapshot->client_id);
        abort_unless($clientSnapshot !== null, 401, 'Registrierung nicht gültig.');

        return DB::transaction(function () use ($data, $snapshot, $clientSnapshot): array {
            [$device, $assignment] = $this->context($clientSnapshot->device_id);
            $client = DeviceDesktopClient::query()->lockForUpdate()->findOrFail($clientSnapshot->id);
            $issuer = User::query()->whereKey($client->created_by)->lockForUpdate()->first();
            abort_unless($issuer?->isActive() && Gate::forUser($issuer)->allows('devices.enrollment.manage'), 403, 'Einladung nicht mehr freigegeben.');
            $enrollment = DeviceDesktopEnrollment::query()->lockForUpdate()->findOrFail($snapshot->id);
            abort_unless($client->status === 'unpaired' && (int) $client->device_assignment_id === (int) $assignment->id
                && $enrollment->claimed_at === null && $enrollment->revoked_at === null
                && $enrollment->expires_at->isFuture() && hash_equals($enrollment->token_hash, hash('sha256', $data['enrollment_token'])), 401, 'Registrierung nicht gültig.');
            $instance = strtolower($data['client_instance_id']);
            abort_if(DeviceDesktopClient::query()->where('instance_id', $instance)->exists(), 409, 'Clientinstanz bereits registriert.');
            $token = $this->token('rtdc_');
            $client->forceFill(['status' => 'active', 'instance_id' => $instance, 'token_hash' => hash('sha256', $token),
                'client_version' => $data['client_version'], 'reported_name' => $data['device_name'] ?? null, 'last_seen_at' => now()])->save();
            $enrollment->forceFill(['claimed_at' => now(), 'token_hash' => hash('sha256', random_bytes(32))])->save();
            $this->audit($client, null, 'paired');

            return $this->response($client, $device) + ['access_token' => $token];
        }, 3);
    }

    public function sync(#[\SensitiveParameter] string $token, array $input): array
    {
        $data = Validator::make($input, [
            'client_version' => ['required', 'string', 'regex:'.self::VERSION_PATTERN],
            'capabilities' => ['sometimes', 'array', 'max:2'],
            'capabilities.*' => ['string', 'in:winget_install,notification', 'distinct'],
            'report' => ['sometimes', 'array:software_runner'],
            'report.software_runner' => ['sometimes', 'in:available,unavailable,unknown'],
        ])->validate();

        return $this->withAuthenticated($token, function (DeviceDesktopClient $client, Device $device) use ($data): array {
            $client->forceFill(['client_version' => $data['client_version'], 'last_seen_at' => now(),
                'last_report' => $data['report'] ?? []])->save();
            $response = $this->response($client, $device);
            // This client binding's explicitly queued installations must all
            // have succeeded. Hidden/expired jobs are not proof of readiness.
            $response['setup_ready'] = ! $client->jobs()->where('type', 'install_software')
                ->where('status', '!=', 'succeeded')->exists();
            if (! $this->settings->productionCommandsEnabled(fresh: true)) {
                return $response;
            }
            $capabilities = $data['capabilities'] ?? [];
            $jobs = $client->jobs()->whereIn('status', ['queued', 'offered'])->orderBy('id')->limit(20)->lockForUpdate()->get();
            foreach ($jobs as $job) {
                if ($job->expires_at->isPast()) {
                    $job->forceFill(['status' => 'expired', 'completed_at' => now()])->save();

                    continue;
                }
                $allowed = $job->type === 'install_software'
                    ? $response['policy']['allow_software_install'] && in_array('winget_install', $capabilities, true)
                    : $response['policy']['allow_notifications'] && in_array('notification', $capabilities, true);
                $requester = User::query()->whereKey($job->requested_by)->lockForUpdate()->first();
                if (! $allowed || ! $requester?->isActive() || ! Gate::forUser($requester)->allows('devices.commands.execute')) {
                    continue;
                }
                if ($job->status === 'queued') {
                    $job->forceFill(['status' => 'offered', 'lease_id' => (string) Str::uuid(), 'offered_at' => now()])->save();
                }
                $response['jobs'][] = ['id' => $job->public_id, 'type' => $job->type, 'lease_id' => $job->lease_id,
                    'expires_at' => $job->expires_at->toIso8601String(), 'payload' => $job->payload];
            }

            return $response;
        });
    }

    public function result(#[\SensitiveParameter] string $token, string $publicId, array $input): array
    {
        $data = Validator::make($input, [
            'lease_id' => ['required', 'uuid'],
            'status' => ['required', 'in:succeeded,failed,requires_admin,unsupported,declined'],
            'exit_code' => ['sometimes', 'nullable', 'integer', 'between:-2147483648,2147483647'],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ])->validate();
        $data['message'] = SafeProviderData::error($data['message'] ?? '');
        $data['exit_code'] = $data['exit_code'] ?? null;
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->withAuthenticated($token, function (DeviceDesktopClient $client) use ($publicId, $data, $hash): array {
            $job = $client->jobs()->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($job && $job->lease_id && hash_equals($job->lease_id, strtolower($data['lease_id'])), 404);
            if ($job->result_hash !== null) {
                abort_unless(hash_equals($job->result_hash, $hash), 409, 'Ein abweichendes Ergebnis wurde bereits gespeichert.');
            } else {
                // A process started before expiry can finish later. This is a
                // truth receipt for the original lease, never a new permission.
                abort_unless(in_array($job->status, ['offered', 'expired'], true) && $job->offered_at !== null, 409, 'Auftrag wurde nicht angeboten.');
                $job->forceFill(['status' => $data['status'], 'result' => $data, 'result_hash' => $hash, 'completed_at' => now()])->save();
                $this->audit($client, null, 'job-result', ['job_id' => $job->public_id, 'status' => $data['status']]);
            }

            return ['accepted' => true, 'job_id' => $job->public_id, 'status' => $job->status];
        });
    }

    public function savePolicy(DeviceDesktopClient $client, array $input, User $actor): void
    {
        $this->authorize($actor, 'devices.manage');
        $rules = array_fill_keys(self::POLICY_KEYS, ['required', 'boolean']);
        $policy = Validator::make($input, $rules)->validate();
        foreach ($policy as &$value) {
            $value = (bool) $value;
        }
        unset($value);
        $this->withClient($client, function (DeviceDesktopClient $locked) use ($policy, $actor): void {
            $this->authorize($actor, 'devices.manage');
            if ($policy['allow_software_install']) {
                $this->authorize($actor, 'devices.commands.execute');
            }
            $locked->forceFill(['policy' => $policy, 'policy_revision' => $locked->policy_revision + 1])->save();
            $this->audit($locked, $actor, 'policy-updated');
        }, allowUnpaired: true);
    }

    public function queueJob(DeviceDesktopClient $client, string $type, array $input, string $justification, User $actor): DeviceDesktopJob
    {
        $this->authorize($actor, 'devices.commands.execute');
        $rules = match ($type) {
            'install_software' => ['package_id' => ['required', 'string', 'max:128', 'regex:'.self::PACKAGE_PATTERN],
                'version' => ['required', 'string', 'regex:'.self::VERSION_PATTERN]],
            'notification' => ['title' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
                'body' => ['required', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/']],
            default => throw ValidationException::withMessages(['type' => 'Dieser Desktop-Auftrag ist nicht erlaubt.']),
        };
        $payload = Validator::make($input, $rules)->validate();
        Validator::make(['justification' => trim($justification)], ['justification' => ['required', 'string', 'min:10', 'max:1000']])->validate();

        return $this->withClient($client, function (DeviceDesktopClient $locked, Device $device) use ($type, $payload, $justification, $actor): DeviceDesktopJob {
            $this->authorize($actor, 'devices.commands.execute');
            app(\App\Services\DeviceManagement\DeviceWorkplaceService::class)->assertCommand($device, $type);
            $key = $type === 'install_software' ? 'allow_software_install' : 'allow_notifications';
            if (($locked->policy[$key] ?? false) !== true) {
                throw ValidationException::withMessages(['policy' => 'Die Gerätrichtlinie erlaubt diesen Auftrag nicht.']);
            }
            if ($locked->jobs()->whereIn('status', ['queued', 'offered'])->count() >= 20) {
                throw ValidationException::withMessages(['job' => 'Für diesen Client sind bereits 20 Aufträge offen.']);
            }
            $job = $locked->jobs()->create(['type' => $type, 'payload' => $payload, 'requested_by' => $actor->id,
                'justification' => trim($justification), 'status' => 'queued', 'expires_at' => now()->addMinutes(30)]);
            $this->audit($locked, $actor, 'job-queued', ['job_id' => $job->public_id, 'type' => $type]);

            return $job;
        });
    }

    public function revoke(DeviceDesktopClient $client, User $actor): void
    {
        $this->authorize($actor, 'devices.enrollment.manage');
        $this->assertStorageReady();
        DB::transaction(function () use ($client, $actor): void {
            Device::withTrashed()->whereKey($client->device_id)->lockForUpdate()->firstOrFail();
            $locked = DeviceDesktopClient::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, 'devices.enrollment.manage');
            $locked->forceFill(['status' => 'revoked', 'token_hash' => null, 'revoked_at' => now()])->save();
            DeviceDesktopEnrollment::query()->where('client_id', $locked->id)->whereNull('claimed_at')->update(['revoked_at' => now()]);
            $locked->jobs()->whereIn('status', ['queued', 'offered'])->update(['status' => 'cancelled', 'completed_at' => now()]);
            $this->audit($locked, $actor, 'revoked');
        }, 3);
    }

    private function response(DeviceDesktopClient $client, Device $device): array
    {
        $policy = $this->defaults();
        foreach (self::POLICY_KEYS as $key) {
            $policy[$key] = ($client->policy[$key] ?? false) === true;
        }
        $policy['allow_software_install'] = $policy['allow_software_install'] && $this->settings->productionCommandsEnabled(fresh: true);
        $policy['revision'] = $client->policy_revision;
        $policy['kiosk_url'] = 'https://app.rail-time.de';

        return ['client_id' => $client->public_id, 'device_id' => $device->public_id,
            'device_name' => $device->display_name ?: $device->hostname ?: 'RailTime-Gerät',
            'sync_interval_seconds' => 60, 'setup_ready' => false, 'policy' => $policy, 'jobs' => []];
    }

    public function withAuthenticated(#[\SensitiveParameter] string $token, callable $action): mixed
    {
        $this->assertStorageReady();
        abort_unless(preg_match('/\Artdc_[A-Za-z0-9_-]{43}\z/', $token), 401);
        $hash = hash('sha256', $token);
        $client = DeviceDesktopClient::query()->where('token_hash', $hash)->first();
        abort_unless($client !== null, 401);

        return $this->withClient($client, function (DeviceDesktopClient $locked, Device $device) use ($hash, $action): mixed {
            abort_unless(is_string($locked->token_hash) && hash_equals($locked->token_hash, $hash), 401);

            return $action($locked, $device);
        });
    }

    private function withClient(DeviceDesktopClient $client, callable $action, bool $allowUnpaired = false): mixed
    {
        $this->assertStorageReady();

        return DB::transaction(function () use ($client, $action, $allowUnpaired): mixed {
            [$device, $assignment] = $this->context($client->device_id);
            $locked = DeviceDesktopClient::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, $allowUnpaired ? ['active', 'unpaired'] : ['active'], true)
                && $locked->revoked_at === null && (int) $locked->device_assignment_id === (int) $assignment->id, 401);

            return $action($locked, $device);
        }, 3);
    }

    private function context(int $deviceId): array
    {
        $device = Device::query()->whereKey($deviceId)->lockForUpdate()->first();
        abort_unless($device && $device->platform === DevicePlatform::Windows, 403, 'Kein freigegebenes Windows-Endgerät.');
        abort_unless(in_array($device->ownership, ['corporate', 'byod'], true)
            && ! in_array($device->lifecycle_status, [DeviceLifecycleStatus::Lost, DeviceLifecycleStatus::Retired], true), 403, 'Kein verfügbares Firmengerät.');
        $metadata = $device->metadata ?? [];
        abort_if(($metadata['controller_only'] ?? false) === true || ($metadata['desktop_controller_only'] ?? false) === true
            || ($metadata['management_role'] ?? '') === 'controller', 403, 'Verwaltungsstationen erhalten keinen Endgeräteclient.');
        $assignments = DeviceAssignment::query()->where('device_id', $deviceId)->active()->lockForUpdate()->get();
        abort_unless($assignments->count() === 1, 403, 'Eindeutige aktive Mitarbeiterzuweisung erforderlich.');
        $assignment = $assignments->first();
        $employee = User::query()->whereKey($assignment->user_id)->lockForUpdate()->first();
        abort_unless($employee?->isActive() && $employee->email_verified_at !== null
            && in_array($employee->role, ['staff', 'admin'], true) && ! $employee->isSuperAdmin(), 403, 'Aktiver verifizierter Mitarbeiter erforderlich.');
        abort_unless(app(\App\Services\DeviceManagement\DeviceWorkplaceService::class)->permitted($device), 403, 'Aktuelle Eigentümerfreigabe fehlt.');

        return [$device, $assignment];
    }

    private function authorize(User $actor, string $permission): void
    {
        $fresh = User::query()->whereKey($actor->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($fresh?->isActive(), 403);
        Gate::forUser($fresh)->authorize($permission);
    }

    private function token(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function audit(DeviceDesktopClient $client, ?User $actor, string $event, array $properties = []): void
    {
        $log = activity('device-management')->performedOn($client)->event('device-desktop.'.$event)
            ->withProperties(['client_id' => $client->public_id, 'device_id' => $client->device_id] + $properties);
        if ($actor) {
            $log->causedBy($actor);
        }
        $log->log('Desktopclient '.$event);
    }
}
