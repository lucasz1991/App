<?php

namespace App\Services\DeviceManagement;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopClient;
use App\Models\DeviceDesktopEnrollment;
use App\Models\DeviceCommand;
use App\Models\DeviceManagementConsent;
use App\Models\DeviceWorkplace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class DeviceWorkplaceService
{
    public function configure(Device $device, string $profileKey, User $actor): DeviceWorkplace
    {
        abort_unless($actor->isActive(), 403);
        Gate::forUser($actor)->authorize('devices.manage');
        WorkplaceProfileCatalog::get($profileKey);
        abort_if(($profileKey === 'byod_managed' && $device->ownership !== 'byod')
            || ($profileKey === 'corporate_standard' && $device->ownership !== 'corporate'), 422, 'Profil und Eigentum passen nicht zusammen.');

        return DB::transaction(function () use ($device, $profileKey, $actor): DeviceWorkplace {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertEndpoint($locked);
            abort_if(($profileKey === 'byod_managed' && $locked->ownership !== 'byod')
                || ($profileKey === 'corporate_standard' && $locked->ownership !== 'corporate'), 422);
            $workplace = DeviceWorkplace::query()->firstOrNew(['device_id' => $locked->id]);
            if ($workplace->exists && $workplace->profile_key === $profileKey && $workplace->ownership === $locked->ownership && ! $workplace->revoked_at) {
                return $workplace;
            }
            $workplace->fill(['profile_key' => $profileKey, 'ownership' => $locked->ownership,
                'revision' => $workplace->exists ? $workplace->revision + 1 : 1,
                'management_state' => 'awaiting_enrollment', 'revoked_at' => null, 'updated_by' => $actor->id])->save();
            DeviceManagementConsent::query()->where('device_id', $locked->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->cancelPending($locked);
            $this->audit($locked, $actor, 'profile-configured', ['profile' => $profileKey, 'revision' => $workplace->revision]);

            return $workplace;
        }, 3);
    }

    public function accept(Device $device, User $owner, int $revision, string $scopeHash, bool $confirmed): DeviceManagementConsent
    {
        abort_unless($confirmed, 422, 'Eigentum und Verwaltungsumfang müssen ausdrücklich bestätigt werden.');

        return DB::transaction(function () use ($device, $owner, $revision, $scopeHash): DeviceManagementConsent {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertEndpoint($locked);
            $assignment = $this->ownerAssignment($locked, $owner);
            $workplace = DeviceWorkplace::query()->where('device_id', $locked->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $workplace->revoked_at && $workplace->ownership === $locked->ownership
                && $workplace->revision === $revision && hash_equals(WorkplaceProfileCatalog::hash($workplace->profile_key), $scopeHash), 409, 'Verwaltungsumfang geändert. Bitte neu lesen.');
            $consent = DeviceManagementConsent::query()->firstOrCreate([
                'device_id' => $locked->id, 'assignment_id' => $assignment->id, 'user_id' => $owner->id,
                'revision' => $revision, 'scope_hash' => $scopeHash, 'revoked_at' => null,
            ], ['scope' => WorkplaceProfileCatalog::scope($workplace->profile_key), 'accepted_at' => now()]);
            $this->audit($locked, $owner, 'consent-accepted', ['revision' => $revision]);

            return $consent;
        }, 3);
    }

    public function permitted(Device $device): bool
    {
        if (! Schema::hasTable('device_workplaces')) {
            return $device->ownership === 'corporate';
        }
        $workplace = DeviceWorkplace::query()->where('device_id', $device->id)->first();
        if (! $workplace) {
            return $device->ownership === 'corporate'; // Preserve existing explicitly enrolled corporate clients.
        }
        if ($workplace->revoked_at || $workplace->ownership !== $device->ownership) {
            return false;
        }
        $assignments = DeviceAssignment::query()->where('device_id', $device->id)->active()->get();
        if ($assignments->count() !== 1) {
            return false;
        }
        $owner = User::query()->find($assignments->first()->user_id);
        if (! $owner?->isActive() || ! $owner->email_verified_at) {
            return false;
        }

        return DeviceManagementConsent::query()->where('device_id', $device->id)
            ->where('assignment_id', $assignments->first()->id)->where('user_id', $assignments->first()->user_id)
            ->where('revision', $workplace->revision)->where('scope_hash', WorkplaceProfileCatalog::hash($workplace->profile_key))
            ->whereNull('revoked_at')->exists();
    }

    public function assertCommand(Device $device, string $command): void
    {
        $this->assertEndpoint($device);
        abort_unless($this->permitted($device), 403, 'Aktuelle Gerätefreigabe fehlt oder wurde widerrufen.');
        if ($device->ownership === 'byod') {
            abort_if(in_array($command, ['wipe', 'lock', 'unlock', 'start_remote_support', 'execute_script', 'restart', 'uninstall_software'], true), 403,
                'Diese Aktion benötigt einen gesonderten, bestätigten Privatgeräte-Ablauf.');
        }
        if (Schema::hasTable('device_workplaces')) {
            $workplace = DeviceWorkplace::query()->where('device_id', $device->id)->first();
            abort_if($workplace?->profile_key === 'railtime_basic' && ! in_array($command, ['sync', 'collect_diagnostics', 'notification'], true), 403,
                'Das Basisprofil erlaubt keine Systemverwaltung.');
        }
    }

    public function revoke(Device $device, User $owner): void
    {
        DB::transaction(function () use ($device, $owner): void {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->ownerAssignment($locked, $owner);
            $workplace = DeviceWorkplace::query()->where('device_id', $locked->id)->lockForUpdate()->firstOrFail();
            $workplace->update(['revoked_at' => now(), 'management_state' => 'cleanup_pending']);
            $this->cancelPending($locked);
            DeviceDesktopEnrollment::query()->whereIn('client_id', DeviceDesktopClient::query()->where('device_id', $locked->id)->select('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DeviceManagementConsent::query()->where('device_id', $locked->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            foreach (DeviceDesktopClient::query()->where('device_id', $locked->id)->get() as $client) {
                $client->update(['status' => 'revoked', 'token_hash' => null, 'revoked_at' => now()]);
                $client->jobs()->whereIn('status', ['queued', 'offered'])->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
            // Cleanup is not claimed here: the external agents must acknowledge actual disconnection.
            $this->audit($locked, $owner, 'consent-revoked', ['cleanup' => 'pending']);
        }, 3);
    }

    public function summary(Device $device): array
    {
        $workplace = Schema::hasTable('device_workplaces') ? DeviceWorkplace::query()->where('device_id', $device->id)->first() : null;

        return ['ownership' => $device->ownership, 'profile_key' => $workplace?->profile_key,
            'revision' => $workplace?->revision, 'consent_current' => $this->permitted($device),
            'management_state' => $workplace?->management_state ?? 'legacy',
            'additional_device_license' => false,
            'scope' => $workplace ? WorkplaceProfileCatalog::scope($workplace->profile_key) : null];
    }

    private function ownerAssignment(Device $device, User $owner): DeviceAssignment
    {
        $owner = $owner->fresh();
        abort_unless($owner->isActive() && $owner->email_verified_at, 403);
        $assignments = DeviceAssignment::query()->where('device_id', $device->id)->active()->lockForUpdate()->get();
        abort_unless($assignments->count() === 1 && (int) $assignments->first()->user_id === (int) $owner->id, 403, 'Nur der zugeordnete Mitarbeiter darf zustimmen.');

        return $assignments->first();
    }

    private function cancelPending(Device $device): void
    {
        DeviceCommand::query()->where('device_id', $device->id)->whereIn('status', ['pending_approval', 'approved', 'queued'])
            ->update(['status' => 'cancelled']);
        foreach (DeviceDesktopClient::query()->where('device_id', $device->id)->get() as $client) {
            $client->jobs()->whereIn('status', ['queued', 'offered'])->update(['status' => 'cancelled', 'completed_at' => now()]);
        }
    }

    private function assertEndpoint(Device $device): void
    {
        $metadata = $device->metadata ?? [];
        abort_if(($metadata['controller_only'] ?? false) || ($metadata['desktop_controller_only'] ?? false)
            || ($metadata['management_role'] ?? '') === 'controller', 403, 'Verwaltungsstationen sind keine Endgeräte.');
        abort_if(in_array($device->lifecycle_status->value, ['lost', 'retired'], true), 403);
    }

    private function audit(Device $device, User $actor, string $event, array $data): void
    {
        activity('device-management')->performedOn($device)->causedBy($actor)->event('device-workplace.'.$event)
            ->withProperties($data)->log('Arbeitsplatz '.$event);
    }
}
