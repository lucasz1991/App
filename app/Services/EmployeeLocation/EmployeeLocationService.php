<?php

namespace App\Services\EmployeeLocation;

use App\Enums\DeviceLifecycleStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeLocationConsent;
use App\Models\EmployeeLocationConsentEvent;
use App\Models\EmployeeLocationPoint;
use App\Models\MobileLocationCode;
use App\Models\MobileLocationInstallation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeLocationService
{
    public const ZONE = 'Europe/Berlin';

    public function pairingCode(User $actor, int $assignmentId): string
    {
        OperationsAccess::own($actor, (int) $actor->id);

        return $this->transaction(function () use ($actor, $assignmentId): string {
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            $this->assertEmployee($actor);
            $assignment = DeviceAssignment::active()->where('user_id', $actor->id)->lockForUpdate()->findOrFail($assignmentId);
            $this->assertAssignment($assignment, $actor);

            return $this->issueCode($actor, $assignment, 'pair', 900);
        });
    }

    public function register(array $input): array
    {
        $data = Validator::make($input, ['code' => 'required|string|min:32|max:128', 'installation_id' => 'required|uuid', 'platform' => 'required|in:android,ios'])->validate();

        return $this->transaction(function () use ($data): array {
            $code = $this->validCode($data['code'], 'pair');
            $actor = User::lockForUpdate()->findOrFail($code->user_id);
            $this->assertEmployee($actor);
            $assignment = DeviceAssignment::lockForUpdate()->findOrFail($code->device_assignment_id);
            $device = $this->assertAssignment($assignment, $actor);
            abort_unless($device->platform->value === $data['platform'], 403, 'Geräteplattform stimmt nicht überein.');
            abort_if(MobileLocationInstallation::where('uuid', $data['installation_id'])->exists(), 409, 'Diese Installation ist bereits registriert.');
            $token = Str::random(80);
            MobileLocationInstallation::where('user_id', $actor->id)->whereNull('revoked_at')->update($this->stoppedFields() + ['revoked_at' => now()->utc()]);
            MobileLocationInstallation::create(['uuid' => $data['installation_id'], 'user_id' => $actor->id, 'device_assignment_id' => $assignment->id, 'platform' => $data['platform'], 'token_hash' => hash('sha256', $token)]);
            $code->forceFill(['consumed_at' => now()->utc()])->save();

            return ['token' => $token, 'installation_id' => $data['installation_id'], 'server_time' => $this->now()->toISOString()];
        });
    }

    public function decide(User $actor, bool $accept, int $explanationVersion): EmployeeLocationConsent
    {
        OperationsAccess::own($actor, (int) $actor->id);

        return $this->transaction(function (array $settings) use ($actor, $accept, $explanationVersion): EmployeeLocationConsent {
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            $this->assertEmployee($actor);
            abort_unless($settings['explanation_version'] === $explanationVersion, 409, 'Die Erklärung wurde geändert. Bitte erneut lesen.');
            $installation = MobileLocationInstallation::where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($accept) {
                $this->check($installation !== null, 'Bitte zuerst den Standortdienst mit deinem Geschäftshandy verbinden.');
                $this->assertAssignment(DeviceAssignment::lockForUpdate()->findOrFail($installation->device_assignment_id), $actor);
            }
            $now = $this->now();
            $consent = EmployeeLocationConsent::updateOrCreate(['user_id' => $actor->id, 'local_date' => $now->setTimezone(self::ZONE)->toDateString(), 'purpose' => 'work_routing'], [
                'device_assignment_id' => $installation?->device_assignment_id, 'installation_id' => $installation?->id,
                'explanation_version' => $settings['explanation_version'], 'explanation_hash' => hash('sha256', $settings['explanation_text']),
                'decision' => $accept ? 'accepted' : 'declined', 'accepted_at' => $accept ? $now : null,
                'expires_at' => $now->setTimezone(self::ZONE)->startOfDay()->addDay()->utc(), 'withdrawn_at' => null,
            ]);
            EmployeeLocationConsentEvent::create(['consent_id' => $consent->id, 'user_id' => $actor->id, 'device_assignment_id' => $installation?->device_assignment_id,
                'installation_id' => $installation?->id, 'action' => $accept ? 'accepted' : 'declined', 'explanation_version' => $settings['explanation_version'],
                'explanation_hash' => $consent->explanation_hash, 'explanation_text' => $settings['explanation_text'], 'occurred_at' => $now]);
            // An explicit new decision never silently restarts a background session.
            $this->stopForUser((int) $actor->id);
            if (! $accept) {
                EmployeeLocationPoint::where('consent_id', $consent->id)->delete();
            }

            return $consent;
        });
    }

    public function withdraw(User $actor): void
    {
        OperationsAccess::own($actor, (int) $actor->id);
        if (! EmployeeLocationSettings::ready()) {
            return;
        }
        DB::transaction(function () use ($actor): void {
            User::lockForUpdate()->findOrFail($actor->id);
            $consents = EmployeeLocationConsent::where('user_id', $actor->id)->where('local_date', $this->now()->setTimezone(self::ZONE)->toDateString())->lockForUpdate()->get();
            foreach ($consents as $consent) {
                if (! $consent->withdrawn_at) {
                    $explained = EmployeeLocationConsentEvent::where('consent_id', $consent->id)->latest('id')->first();
                    EmployeeLocationConsentEvent::create(['consent_id' => $consent->id, 'user_id' => $actor->id, 'device_assignment_id' => $consent->device_assignment_id,
                        'installation_id' => $consent->installation_id, 'action' => 'withdrawn', 'explanation_version' => $consent->explanation_version,
                        'explanation_hash' => $consent->explanation_hash, 'explanation_text' => $explained?->explanation_text ?? '', 'occurred_at' => $this->now()]);
                }
                $consent->forceFill(['withdrawn_at' => $this->now()])->save();
            }
            $this->stopForUser((int) $actor->id);
            MobileLocationCode::where('user_id', $actor->id)->where('purpose', 'start')->whereNull('consumed_at')->update(['consumed_at' => $this->now()]);
            EmployeeLocationPoint::whereIn('consent_id', $consents->pluck('id'))->delete();
        }, 3);
    }

    public function deleteHistory(User $actor): void
    {
        OperationsAccess::own($actor, (int) $actor->id);
        if (! EmployeeLocationSettings::ready()) {
            return;
        }
        DB::transaction(function () use ($actor): void {
            User::lockForUpdate()->findOrFail($actor->id);
            $this->withdraw($actor);
            EmployeeLocationPoint::where('user_id', $actor->id)->delete();
        }, 3);
    }

    public function handoff(User $actor): string
    {
        OperationsAccess::own($actor, (int) $actor->id);

        return $this->transaction(function (array $settings) use ($actor): string {
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            $installation = MobileLocationInstallation::where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
            [$assignment] = $this->authorizedState($actor, $installation, $settings);

            return $this->issueCode($actor, $assignment, 'start', 60, $installation->id);
        });
    }

    public function start(MobileLocationInstallation $installation, string $rawCode): array
    {
        return $this->transaction(function (array $settings) use ($installation, $rawCode): array {
            $actor = User::lockForUpdate()->findOrFail($installation->user_id);
            $installation = MobileLocationInstallation::lockForUpdate()->findOrFail($installation->id);
            $code = $this->validCode($rawCode, 'start');
            abort_unless((int) $code->installation_id === $installation->id && (int) $code->user_id === $actor->id && (int) $code->device_assignment_id === $installation->device_assignment_id, 403);
            $this->authorizedState($actor, $installation, $settings);
            $installation->forceFill(['session_id' => (string) Str::uuid(), 'session_started_at' => $this->now(), 'permit_id' => null, 'next_sample_at' => $this->now()])->save();
            $code->forceFill(['consumed_at' => $this->now()])->save();

            return $this->issuePermit($actor, $installation, $settings);
        });
    }

    public function permit(MobileLocationInstallation $installation): array
    {
        return $this->transaction(function (array $settings) use ($installation): array {
            $actor = User::lockForUpdate()->findOrFail($installation->user_id);
            $installation = MobileLocationInstallation::lockForUpdate()->findOrFail($installation->id);
            abort_unless($installation->session_id && $installation->permit_expires_at?->isFuture(), 403, 'Standortdienst muss sichtbar neu gestartet werden.');

            return $this->issuePermit($actor, $installation, $settings);
        });
    }

    public function point(MobileLocationInstallation $installation, array $input): array
    {
        $data = Validator::make($input, [
            'permit_id' => 'required|uuid', 'event_id' => 'required|uuid', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'accuracy_m' => 'required|numeric|min:0|max:100000', 'measured_at' => 'required|date',
        ])->validate();
        $response = $this->transaction(function (array $settings) use ($installation, $data): array {
            $actor = User::lockForUpdate()->findOrFail($installation->user_id);
            $installation = MobileLocationInstallation::lockForUpdate()->findOrFail($installation->id);
            [, $consent, $entry] = $this->authorizedState($actor, $installation, $settings);
            $now = $this->now();
            abort_unless($installation->session_id && $installation->permit_expires_at?->gt($now)
                && hash_equals((string) $installation->permit_id, $data['permit_id'])
                && $installation->work_time_entry_id === (int) $entry->id && $installation->work_revision === (int) $entry->revision
                && $installation->consent_id === (int) $consent->id, 403, 'Erfassungserlaubnis ist abgelaufen.');
            if ((float) $data['accuracy_m'] > 50) {
                // Commit just the authorized quality state, then return a validation error.
                $installation->forceFill(['quality_status' => 'inaccurate'])->save();

                return ['quality_rejected' => true];
            }
            $existing = EmployeeLocationPoint::where('installation_id', $installation->id)->where('event_id', $data['event_id'])->first();
            if ($existing) {
                $same = abs(($existing->position['latitude'] ?? 999) - (float) $data['latitude']) < 0.0000001
                    && abs(($existing->position['longitude'] ?? 999) - (float) $data['longitude']) < 0.0000001
                    && abs($existing->accuracy_m - (float) $data['accuracy_m']) < 0.01
                    && $existing->measured_at->equalTo(CarbonImmutable::parse($data['measured_at'])->utc());
                abort_unless($same, 409, 'Wiederholung enthält geänderte Messdaten.');

                return $this->pointResponse($installation, true);
            }
            $measured = CarbonImmutable::parse($data['measured_at'])->utc();
            $this->check($measured->gte($now->subSeconds(30)) && $measured->lte($now->addSeconds(5)), 'Veraltete oder zukünftige Messung.');
            $this->check($measured->gte($consent->accepted_at) && $measured->gte($entry->starts_at->utc()) && $measured->gte($installation->session_started_at), 'Messung liegt außerhalb der aktiven Erfassung.');
            $this->check(! $installation->next_sample_at || $measured->gte($installation->next_sample_at), 'Das nächste 15-Minuten-Intervall ist noch nicht erreicht.');
            EmployeeLocationPoint::create([
                'user_id' => $actor->id, 'installation_id' => $installation->id, 'device_assignment_id' => $installation->device_assignment_id,
                'consent_id' => $consent->id, 'work_time_entry_id' => $entry->id, 'event_id' => $data['event_id'],
                'position' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']],
                'accuracy_m' => (float) $data['accuracy_m'], 'measured_at' => $measured, 'received_at' => $now,
            ]);
            $installation->forceFill(['last_sample_at' => $measured, 'next_sample_at' => $measured->addSeconds(900), 'quality_status' => 'precise', 'last_seen_at' => $now])->save();

            return $this->pointResponse($installation);
        });
        $this->check(! ($response['quality_rejected'] ?? false), 'Messung ist ungenau (mehr als 50 Meter).');

        return $response;
    }

    public function quality(MobileLocationInstallation $installation, array $input): array
    {
        $this->check(array_diff(array_keys($input), ['permit_id', 'quality_status', 'accuracy_m']) === [], 'Qualitätsmeldungen dürfen keine Standort- oder Zeitdaten enthalten.');
        $data = Validator::make($input, ['permit_id' => 'required|uuid', 'quality_status' => 'required|in:inaccurate,unavailable', 'accuracy_m' => 'nullable|numeric|min:0|max:100000'])->validate();

        return $this->transaction(function (array $settings) use ($installation, $data): array {
            $actor = User::lockForUpdate()->findOrFail($installation->user_id);
            $installation = MobileLocationInstallation::lockForUpdate()->findOrFail($installation->id);
            [, $consent, $entry] = $this->authorizedState($actor, $installation, $settings);
            $now = $this->now();
            abort_unless($installation->session_id && $installation->permit_expires_at?->gt($now)
                && hash_equals((string) $installation->permit_id, $data['permit_id'])
                && $installation->work_time_entry_id === (int) $entry->id && $installation->work_revision === (int) $entry->revision
                && $installation->consent_id === (int) $consent->id, 403, 'Erfassungserlaubnis ist abgelaufen.');
            $this->check(! $installation->next_sample_at || $now->gte($installation->next_sample_at), 'Das nächste 15-Minuten-Intervall ist noch nicht erreicht.');
            if ($installation->last_quality_at?->addSeconds(60)->gt($now)) {
                return ['reported' => false, 'throttled' => true, 'server_time' => $now->toISOString()];
            }
            $installation->forceFill(['quality_status' => $data['quality_status'], 'last_quality_at' => $now, 'last_seen_at' => $now])->save();

            return ['reported' => true, 'server_time' => $now->toISOString()];
        });
    }

    public function stop(MobileLocationInstallation $installation): void
    {
        if (EmployeeLocationSettings::ready()) {
            DB::transaction(function () use ($installation): void {
                $owner = User::lockForUpdate()->find($installation->user_id);
                if (! $owner) {
                    return;
                }
                $locked = MobileLocationInstallation::where('user_id', $owner->id)->lockForUpdate()->find($installation->id);
                if (! $locked) {
                    return;
                }
                $locked->forceFill($this->stoppedFields())->save();
                MobileLocationCode::where('installation_id', $locked->id)->where('purpose', 'start')->whereNull('consumed_at')->update(['consumed_at' => $this->now()]);
            }, 3);
        }
    }

    public function stopForUser(int $userId): void
    {
        if (EmployeeLocationSettings::ready()) {
            DB::transaction(function () use ($userId): void {
                User::lockForUpdate()->find($userId);
                MobileLocationInstallation::where('user_id', $userId)->update($this->stoppedFields());
                MobileLocationCode::where('user_id', $userId)->where('purpose', 'start')->whereNull('consumed_at')->update(['consumed_at' => $this->now()]);
            }, 3);
        }
    }

    public function revokeAssignment(int $assignmentId): void
    {
        if (EmployeeLocationSettings::ready()) {
            MobileLocationInstallation::where('device_assignment_id', $assignmentId)->update($this->stoppedFields() + ['revoked_at' => $this->now()]);
            MobileLocationCode::where('device_assignment_id', $assignmentId)->whereNull('consumed_at')->update(['consumed_at' => $this->now()]);
        }
    }

    public function status(User $actor, bool $includeHistory = false): array
    {
        OperationsAccess::own($actor, (int) $actor->id);
        $settings = EmployeeLocationSettings::read();
        $ready = EmployeeLocationSettings::ready();
        $result = ['enabled' => (bool) $settings['enabled'] && $ready, 'settings' => $settings, 'state' => 'disabled', 'consent' => null, 'installation' => null, 'assignments' => collect(), 'history' => collect(), 'last_point' => null, 'running' => false, 'needs_decision' => false];
        if (! $ready) {
            return $result;
        }
        $now = $this->now();
        $points = EmployeeLocationPoint::where('user_id', $actor->id)->where('measured_at', '>', $now->subDays(7)->format('Y-m-d H:i:s.u'))->latest('measured_at');
        $lastPoint = (clone $points)->first();
        $history = $includeHistory ? $points->limit(700)->get() : collect();
        $result = array_replace($result, ['history' => $history, 'last_point' => $lastPoint]);
        if (! $result['enabled']) {
            return $result;
        }
        $installation = MobileLocationInstallation::where('user_id', $actor->id)->whereNull('revoked_at')->with('assignment.device')->first();
        $consent = EmployeeLocationConsent::where('user_id', $actor->id)->where('local_date', $now->setTimezone(self::ZONE)->toDateString())->first();
        $entry = WorkTimeEntry::where('user_id', $actor->id)->whereIn('status', ['running', 'paused'])->latest('id')->first();
        $granted = $consent && $consent->decision === 'accepted' && ! $consent->withdrawn_at && $consent->expires_at->gt($now) && $consent->explanation_version === $settings['explanation_version'] && $consent->installation_id === $installation?->id;
        $qualityState = in_array($installation?->quality_status, ['inaccurate', 'unavailable'], true) ? $installation->quality_status : 'active';
        $state = ! $installation ? 'unpaired' : (! $granted ? 'consent_missing' : (! $entry ? 'not_working' : ($entry->status === 'paused' ? 'paused' : (! $installation->session_id ? 'start_required' : (! $installation->permit_expires_at?->gt($now) ? 'offline' : $qualityState)))));

        return array_replace($result, ['state' => $state, 'consent' => $consent, 'installation' => $installation, 'history' => $history, 'last_point' => $lastPoint, 'running' => $entry?->status === 'running',
            'needs_decision' => $entry?->status === 'running' && (! $consent || $consent->explanation_version !== $settings['explanation_version']),
            'assignments' => DeviceAssignment::active()->where('user_id', $actor->id)->whereHas('device', fn ($query) => $query->where('ownership', 'corporate')->whereIn('platform', ['android', 'ios'])->whereIn('lifecycle_status', ['assigned', 'in_service']))->with('device')->get(),
        ]);
    }

    public function purge(): int
    {
        if (! EmployeeLocationSettings::ready()) {
            return 0;
        }
        $cutoff = $this->now()->subDays(7);
        $count = 0;
        do {
            $ids = EmployeeLocationPoint::where('measured_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('id')->limit(1000)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : EmployeeLocationPoint::whereIn('id', $ids)->delete();
            $count += $deleted;
        } while ($deleted === 1000);
        MobileLocationCode::where('expires_at', '<=', $this->now()->format('Y-m-d H:i:s.u'))->delete();
        MobileLocationInstallation::where('permit_expires_at', '<=', $this->now()->format('Y-m-d H:i:s.u'))->update($this->stoppedFields());

        return $count;
    }

    private function issuePermit(User $actor, MobileLocationInstallation $installation, array $settings): array
    {
        [, $consent, $entry] = $this->authorizedState($actor, $installation, $settings);
        $now = $this->now();
        $expires = $now->addSeconds(120)->min($consent->expires_at);
        $installation->forceFill(['permit_id' => $installation->permit_id ?? (string) Str::uuid(), 'permit_expires_at' => $expires,
            'consent_id' => $consent->id, 'work_time_entry_id' => $entry->id, 'work_revision' => $entry->revision, 'last_seen_at' => $now])->save();

        return ['permit_id' => $installation->permit_id, 'expires_at' => $expires->toISOString(), 'server_time' => $now->toISOString(), 'next_sample_at' => ($installation->next_sample_at ?? $now)->toISOString(), 'work_time_entry_id' => (int) $entry->id];
    }

    private function authorizedState(User $actor, MobileLocationInstallation $installation, array $settings): array
    {
        $this->assertEmployee($actor);
        abort_unless(! $installation->revoked_at && $installation->user_id === (int) $actor->id, 403);
        $assignment = DeviceAssignment::lockForUpdate()->findOrFail($installation->device_assignment_id);
        $this->assertAssignment($assignment, $actor);
        $consent = EmployeeLocationConsent::where('user_id', $actor->id)->where('local_date', $this->now()->setTimezone(self::ZONE)->toDateString())->lockForUpdate()->first();
        abort_unless($consent && $consent->decision === 'accepted' && ! $consent->withdrawn_at && $consent->expires_at->gt($this->now())
            && $consent->explanation_version === $settings['explanation_version'] && $consent->installation_id === $installation->id
            && $consent->device_assignment_id === $assignment->id && hash_equals($consent->explanation_hash, hash('sha256', $settings['explanation_text'])), 403, 'Tagesfreigabe fehlt oder ist abgelaufen.');
        $entry = WorkTimeEntry::where('user_id', $actor->id)->where('status', 'running')->whereNull('ends_at')->lockForUpdate()->first();
        abort_unless($entry && ! $entry->paused_at && $entry->starts_at->lte($this->now()), 403, 'Es läuft keine Arbeitszeiterfassung.');

        return [$assignment, $consent, $entry];
    }

    private function assertEmployee(User $actor): void
    {
        abort_unless(OperationsAccess::isEmployee($actor), 403);
    }

    private function assertAssignment(DeviceAssignment $assignment, User $actor): Device
    {
        abort_unless($assignment->status === DeviceAssignment::STATUS_ACTIVE && ! $assignment->returned_at && (int) $assignment->user_id === (int) $actor->id, 403, 'Geschäftshandy ist nicht mehr zugeordnet.');
        $device = Device::lockForUpdate()->findOrFail($assignment->device_id);
        abort_unless($device->ownership === 'corporate' && in_array($device->platform, [DevicePlatform::Android, DevicePlatform::IOS], true)
            && in_array($device->lifecycle_status, [DeviceLifecycleStatus::Assigned, DeviceLifecycleStatus::InService], true), 403, 'Nur aktive Geschäftshandys sind zulässig.');

        return $device;
    }

    private function issueCode(User $actor, DeviceAssignment $assignment, string $purpose, int $seconds, ?int $installationId = null): string
    {
        MobileLocationCode::where('user_id', $actor->id)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => $this->now()]);
        $code = Str::random(48);
        MobileLocationCode::create(['user_id' => $actor->id, 'device_assignment_id' => $assignment->id, 'installation_id' => $installationId, 'code_hash' => hash('sha256', $code), 'purpose' => $purpose, 'expires_at' => $this->now()->addSeconds($seconds)]);

        return $code;
    }

    private function validCode(string $raw, string $purpose): MobileLocationCode
    {
        $code = MobileLocationCode::where('code_hash', hash('sha256', $raw))->where('purpose', $purpose)->lockForUpdate()->first();
        abort_unless($code && ! $code->consumed_at && $code->expires_at->gt($this->now()), 403, 'Verbindungscode ist ungültig oder abgelaufen.');

        return $code;
    }

    private function transaction(callable $callback): mixed
    {
        abort_unless(EmployeeLocationSettings::ready(), 503, 'Standortfunktion ist noch nicht eingerichtet.');

        return DB::transaction(function () use ($callback): mixed {
            // Serialize with global disable/purpose changes before any user or sample locks.
            $setting = Setting::where('type', EmployeeLocationSettings::GROUP)->where('key', EmployeeLocationSettings::KEY)->lockForUpdate()->first();
            $settings = array_replace(EmployeeLocationSettings::DEFAULTS, (array) $setting?->value);
            abort_unless($settings['enabled'], 403, 'Standortfunktion ist deaktiviert.');

            return $callback($settings);
        }, 3);
    }

    private function pointResponse(MobileLocationInstallation $installation, bool $duplicate = false): array
    {
        return ['accepted' => true, 'duplicate' => $duplicate, 'next_sample_at' => $installation->next_sample_at?->toISOString(), 'server_time' => $this->now()->toISOString()];
    }

    private function stoppedFields(): array
    {
        return ['session_id' => null, 'session_started_at' => null, 'permit_id' => null, 'permit_expires_at' => null, 'quality_status' => 'stopped'];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['location' => $message]);
        }
    }
}
