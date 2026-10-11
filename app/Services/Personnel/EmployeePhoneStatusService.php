<?php

namespace App\Services\Personnel;

use App\Enums\DeviceLifecycleStatus;
use App\Models\DeviceAssignment;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only profile projection. No browser, push or location collection is
 * activated, and an assigned phone is never promoted to a primary phone.
 */
final class EmployeePhoneStatusService
{
    public function read(int $userId, ?User $actor = null): ?array
    {
        $actor ??= auth()->user();
        if (! $actor instanceof User) {
            return null;
        }

        // A hydrated actor or cached team must not retain a revoked permission.
        $actor = User::with('currentTeam')->find($actor->getKey());
        if (! $actor?->isActive() || ! $actor->canViewManagementDashboard()
            || ! Gate::forUser($actor)->allows('employees.view')
            || ! Gate::forUser($actor)->allows('devices.view')) {
            return null;
        }

        $employee = User::query()->find($userId, ['id', 'role']);
        if (! $employee || $employee->role !== 'staff'
            || ! app(PersonnelScopeService::class)->allows($actor, $employee, 'employees.master-data.view')) {
            return null;
        }

        // The current schema has no per-employee main-phone designation. PWA
        // display mode/installation and browser permissions remain client-local.
        // User-level push subscriptions cannot identify a handset; native GPS
        // app registration or daily consent is neither PWA installation nor an
        // OS permission receipt. Do not query those sensitive stores here.
        return [
            'primary_state' => 'unset',
            'device_label' => 'Haupthandy nicht festgelegt',
            'assigned_phone_count' => $this->assignedPhoneCount($userId),
            'rows' => [
                'installation' => $this->unknown('Web-App', 'far fa-mobile-screen-button'),
                'push' => $this->unknown('Push-Freigabe', 'far fa-bell'),
                'location' => $this->unknown('Standortberechtigung', 'far fa-location-dot'),
            ],
            'evidence_note' => 'Haupthandy und gerätebezogene App-/Berechtigungsnachweise sind noch nicht hinterlegt.',
        ];
    }

    private function assignedPhoneCount(int $userId): ?int
    {
        // Partially deployed/missing device storage is unknown, not zero.
        if (! Schema::hasColumns('device_assignments', ['device_id', 'user_id', 'status', 'assigned_at', 'returned_at'])
            || ! Schema::hasColumns('devices', ['id', 'form_factor', 'deleted_at', 'lifecycle_status'])) {
            return null;
        }

        return DB::table('device_assignments')
            ->join('devices', 'devices.id', '=', 'device_assignments.device_id')
            ->where('device_assignments.user_id', $userId)
            ->where('device_assignments.status', DeviceAssignment::STATUS_ACTIVE)
            ->where('device_assignments.assigned_at', '<=', now())
            ->whereNull('device_assignments.returned_at')
            ->where('devices.form_factor', 'phone')
            ->whereNull('devices.deleted_at')
            ->where('devices.lifecycle_status', '!=', DeviceLifecycleStatus::Retired->value)
            ->distinct()->count('devices.id');
    }

    /** @return array{label:string,icon:string,state:string,value:string} */
    private function unknown(string $label, string $icon): array
    {
        return ['label' => $label, 'icon' => $icon, 'state' => 'unknown', 'value' => 'Nicht gemeldet'];
    }
}
