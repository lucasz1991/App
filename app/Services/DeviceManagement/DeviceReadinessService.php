<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceManagementStatus;
use App\Models\Device;
use App\Models\DeviceReadinessCheck;
use App\Models\User;
use Illuminate\Support\Collection;

class DeviceReadinessService
{
    public function __construct(private readonly DeviceManagementSettings $settings) {}

    /** @var array<string, string> */
    public const REQUIRED_CHECKS = [
        'assignment' => 'Mitarbeiter zugewiesen',
        'enrollment' => 'Gerät eingeschrieben',
        'provider_sync' => 'Aktuelle Provider-Synchronisierung',
        'identity' => 'Identität und Lizenz belegt',
        'profiles' => 'Pflichtprofile und Apps angewendet',
        'user_sign_in' => 'OAuth/SSO/MFA abgeschlossen',
        'network' => 'WLAN/VPN geprüft',
        'certificate' => 'Gerätezertifikat gültig',
        'remote_support' => 'Fernsupport erreichbar',
        'compliance' => 'Keine blockierende Compliance-Abweichung',
    ];

    /**
     * Aktualisiert nur Zustände, die RailTime selbst belegen kann. Technische
     * Checks bleiben so lange unbekannt, bis ein signierter Providerbeleg sie
     * aktualisiert; eine versendete Einladung gilt nie als Erfolg.
     *
     * @return Collection<int, DeviceReadinessCheck>
     */
    public function refresh(Device $device, ?User $actor = null): Collection
    {
        $device->loadMissing([
            'activeAssignment.user.identityAccounts',
            'accountAssignments',
            'enrollments',
            'readinessChecks',
        ]);

        $assignment = $device->activeAssignment;
        $this->record($device, 'assignment', $assignment ? 'passed' : 'blocked', 'railtime', [
            'assignment_id' => $assignment?->id,
            'user_id' => $assignment?->user_id,
        ]);

        $hasCompletedEnrollment = $device->enrollments->contains(
            fn ($enrollment): bool => $enrollment->status->value === 'completed',
        );
        $managed = in_array($device->management_status, [
            DeviceManagementStatus::Managed,
            DeviceManagementStatus::Limited,
        ], true);
        $this->record(
            $device,
            'enrollment',
            ($hasCompletedEnrollment || $managed) ? 'passed' : 'pending',
            $hasCompletedEnrollment ? 'provider_receipt' : 'railtime',
            ['management_status' => $device->management_status->value],
        );

        $maxAgeHours = $this->settings->maxSyncAgeHours();
        $syncStatus = ! $device->last_synced_at
            ? 'unknown'
            : ($device->last_synced_at->gte(now()->subHours($maxAgeHours)) ? 'passed' : 'stale');
        $this->record($device, 'provider_sync', $syncStatus, 'provider', [
            'last_synced_at' => $device->last_synced_at?->toIso8601String(),
            'max_age_hours' => $maxAgeHours,
        ]);

        $identities = $assignment?->user?->identityAccounts ?? collect();
        $identityPassed = $identities->isNotEmpty()
            && $identities->every(fn ($identity): bool => $identity->lifecycle_status === 'active'
                && in_array($identity->provisioning_status, ['applied', 'ready'], true)
                && in_array($identity->license_status, ['assigned', 'active', 'not_required'], true));
        $this->record($device, 'identity', $identityPassed ? 'passed' : 'pending', 'identity_provider', [
            'account_count' => $identities->count(),
            'verified_accounts' => $identities->filter(fn ($identity): bool => in_array(
                $identity->provisioning_status,
                ['applied', 'ready'],
                true,
            ))->count(),
        ]);

        $profileAssignments = $device->accountAssignments;
        $profilesPassed = $profileAssignments->isNotEmpty()
            && $profileAssignments->every(fn ($profile): bool => in_array($profile->status, ['applied', 'ready'], true));
        $this->record($device, 'profiles', $profilesPassed ? 'passed' : 'pending', 'provider', [
            'profile_count' => $profileAssignments->count(),
            'applied_count' => $profileAssignments->filter(fn ($profile): bool => in_array(
                $profile->status,
                ['applied', 'ready'],
                true,
            ))->count(),
        ]);

        $this->ensureTechnicalChecks($device);

        $complianceStatus = match ($device->compliance_status) {
            DeviceComplianceStatus::Compliant => 'passed',
            DeviceComplianceStatus::Warning => 'warning',
            DeviceComplianceStatus::NonCompliant => 'blocked',
            DeviceComplianceStatus::Exempt => 'not_applicable',
            default => 'unknown',
        };
        $this->record($device, 'compliance', $complianceStatus, 'provider', [
            'compliance_status' => $device->compliance_status->value,
        ]);

        $checks = $device->readinessChecks()->get();

        if ($actor) {
            activity('device-management')
                ->causedBy($actor)
                ->performedOn($device)
                ->withProperties([
                    'device_public_id' => $device->public_id,
                    'ready' => $this->checksAreReady($checks),
                ])
                ->log('device_readiness_refreshed');
        }

        return $checks;
    }

    public function isReady(Device $device): bool
    {
        $checks = $device->readinessChecks()->get();

        return $this->checksAreReady($checks);
    }

    /**
     * @param  Collection<int, DeviceReadinessCheck>  $checks
     */
    private function checksAreReady(Collection $checks): bool
    {
        $indexed = $checks->keyBy('check_key');

        return collect(array_keys(self::REQUIRED_CHECKS))->every(
            fn (string $key): bool => in_array($indexed->get($key)?->status, ['passed', 'not_applicable'], true),
        );
    }

    private function ensureTechnicalChecks(Device $device): void
    {
        foreach (['user_sign_in', 'network', 'certificate'] as $key) {
            if (! $device->readinessChecks()->where('check_key', $key)->exists()) {
                $this->record($device, $key, 'unknown', 'provider', []);
            }
        }

        $desktop = in_array($device->platform->value, ['windows', 'macos', 'linux'], true);
        if (! $desktop) {
            $this->record($device, 'remote_support', 'not_applicable', 'railtime', [
                'reason' => 'Kein unbeaufsichtigter Desktop-Fernsupport fuer diese Plattform vorausgesetzt.',
            ]);

            return;
        }

        if (! $device->readinessChecks()->where('check_key', 'remote_support')->exists()) {
            $this->record($device, 'remote_support', 'unknown', 'remote_provider', []);
        }
    }

    /** @param array<string, mixed> $evidence */
    private function record(Device $device, string $key, string $status, string $source, array $evidence): void
    {
        DeviceReadinessCheck::query()->updateOrCreate(
            ['device_id' => $device->id, 'check_key' => $key],
            [
                'label' => self::REQUIRED_CHECKS[$key],
                'status' => $status,
                'source' => $source,
                'evidence' => $evidence,
                'checked_at' => now(),
            ],
        );
    }
}
