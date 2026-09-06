<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MicrosoftEmployeeLinkService
{
    public function __construct(private readonly MicrosoftDeviceSettings $settings) {}

    public function bind(User $employee, string $objectId, string $principal, User $actor): EmployeeIdentityAccount
    {
        Gate::forUser($actor)->authorize('devices.accounts.manage');

        $data = validator([
            'employee_id' => $employee->getKey(),
            'object_id' => strtolower(trim($objectId)),
            'principal' => mb_strtolower(trim($principal)),
        ], [
            'employee_id' => ['required', 'integer'],
            'object_id' => ['required', 'uuid'],
            'principal' => ['required', 'email:rfc', 'max:191'],
        ], [], [
            'employee_id' => 'Mitarbeiter',
            'object_id' => 'Microsoft-Objekt-ID',
            'principal' => 'Microsoft-Anmeldename',
        ])->validate();

        $tenantId = strtolower(trim((string) ($this->settings->configuration()['tenant_id'] ?? '')));
        if (! Str::isUuid($tenantId)) {
            throw ValidationException::withMessages([
                'tenant_id' => 'Bitte zuerst den konkreten Microsoft-Mandanten in den Geräteeinstellungen konfigurieren.',
            ]);
        }

        try {
            return DB::transaction(function () use ($employee, $actor, $data, $tenantId): EmployeeIdentityAccount {
                $lockedEmployee = User::query()->lockForUpdate()->findOrFail($employee->getKey());
                if (! $lockedEmployee->isActive()) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'Microsoft-Konten können nur aktiven Mitarbeitern zugeordnet werden.',
                    ]);
                }

                // Employee lock serializes first bindings; provider uniqueness
                // constraints arbitrate concurrent claims by different people.
                $candidates = EmployeeIdentityAccount::query()
                    ->forProvider(AccountProvider::Microsoft365)
                    ->where(function ($query) use ($lockedEmployee, $data): void {
                        $query->where('user_id', $lockedEmployee->getKey())
                            ->orWhereRaw('LOWER(external_id) = ?', [$data['object_id']])
                            ->orWhereRaw('LOWER(principal) = ?', [$data['principal']]);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($candidates->count() > 1) {
                    throw $this->conflict('Die Angaben betreffen mehrere bestehende Kontobindungen. Bitte die Zuordnung zuerst prüfen.');
                }

                $identity = $candidates->first();
                if ($identity) {
                    if ((int) $identity->user_id !== (int) $lockedEmployee->getKey()
                        || mb_strtolower((string) $identity->principal) !== $data['principal']) {
                        throw $this->conflict('Das Microsoft-Konto oder der Mitarbeiter ist bereits abweichend zugeordnet.');
                    }

                    $existingObjectId = strtolower(trim((string) $identity->external_id));
                    $existingTenantId = strtolower(trim((string) $identity->tenant_id));
                    if (($existingObjectId !== '' && $existingObjectId !== $data['object_id'])
                        || ($existingTenantId !== '' && $existingTenantId !== $tenantId)
                        || ($existingObjectId !== '' && $existingTenantId === '')
                        || $identity->lifecycle_status !== 'active') {
                        throw $this->conflict('Die bestehende Objekt-ID, der Mandant oder der Kontostatus passt nicht. Eine vorhandene Bindung wird nicht ersetzt.');
                    }

                    if ($existingObjectId === $data['object_id'] && $existingTenantId === $tenantId) {
                        return $identity;
                    }

                    if (! in_array($identity->provisioning_status, ['pending_provider', 'pending'], true)) {
                        throw $this->conflict('Dieses Konto ist keine offene Erstzuordnung. Bitte die vorhandenen Providerdaten prüfen.');
                    }

                    $identity->forceFill([
                        'external_id' => $data['object_id'],
                        'tenant_id' => $tenantId,
                        'metadata' => array_merge((array) $identity->metadata, $this->linkMetadata()),
                    ])->save();
                } else {
                    $identity = EmployeeIdentityAccount::query()->create([
                        'user_id' => $lockedEmployee->getKey(),
                        'provider' => AccountProvider::Microsoft365,
                        'external_id' => $data['object_id'],
                        'tenant_id' => $tenantId,
                        'principal' => $data['principal'],
                        'email' => $data['principal'],
                        'lifecycle_status' => 'active',
                        'provisioning_status' => 'pending_provider',
                        'license_status' => 'unknown',
                        'metadata' => $this->linkMetadata(),
                    ]);
                }

                activity('device-management')
                    ->causedBy($actor)
                    ->performedOn($identity)
                    ->withProperties([
                        'employee_id' => $lockedEmployee->getKey(),
                        'identity_id' => $identity->getKey(),
                        'source' => 'administrator_mapping',
                    ])
                    ->log('microsoft_employee_linked');

                return $identity;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->conflict('Das Microsoft-Konto wurde inzwischen anderweitig zugeordnet. Bitte die Ansicht neu laden.');
        }
    }

    private function linkMetadata(): array
    {
        return [
            'microsoft_link_source' => 'administrator_mapping',
            'microsoft_linked_at' => now()->toIso8601String(),
        ];
    }

    private function conflict(string $message): ValidationException
    {
        return ValidationException::withMessages(['object_id' => $message]);
    }
}
