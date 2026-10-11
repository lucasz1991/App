<?php

namespace App\Services\Personnel;

use App\Enums\DeviceLifecycleStatus;
use App\Models\DeviceAssignment;
use App\Models\User;
use App\Models\UserAppRuntimeReceipt;
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

        // Runtime reports describe their own mobile session. They never prove
        // an inventory assignment, a main handset or native background tracking.
        $result = [
            'primary_state' => 'unset',
            'device_label' => 'Haupthandy nicht festgelegt',
            'assigned_phone_count' => $this->assignedPhoneCount($userId),
            'rows' => [
                'installation' => $this->unknown('Web-App', 'far fa-mobile-screen-button'),
                'push' => $this->unknown('Push-Freigabe', 'far fa-bell'),
                'location' => $this->unknown('Standortberechtigung', 'far fa-location-dot'),
            ],
            'evidence_note' => 'Haupthandy und gerätebezogene App-/Berechtigungsnachweise sind noch nicht hinterlegt. Aus einer mobilen App-Sitzung wurde noch kein Status empfangen. Bitte RailTime auf dem Handy neu öffnen, damit die Web-App ihren Einrichtungsstatus melden kann.',
        ];
        $receipts = app(UserAppRuntimeReceiptService::class);
        if (! $receipts->ready() || ! ($receipt = $receipts->latestMobileForUser($userId))) {
            return $result;
        }

        $fresh = $receipts->isFresh($receipt);
        $reportedAt = $receipt->received_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i');
        $result['device_label'] = 'Gemeldete '.($receipt->platform === 'ios' ? 'iOS' : 'Android').'-Sitzung · Haupthandy nicht festgelegt';
        $result['evidence_note'] = ($fresh ? 'Meldung vom ' : 'Zuletzt gemeldet am ').$reportedAt.' Uhr. '
            .($fresh ? 'Der Browser hat diese Zustände gemeldet; die Meldung ist höchstens 15 Minuten alt.' : 'Die Meldung ist älter als 15 Minuten und beschreibt keinen aktuellen Gerätestatus.')
            .' Ein App-Bericht legt kein Haupthandy und keine Inventarzuordnung fest.';
        $result['rows'] = [
            'installation' => $this->runtimeRow('Web-App', 'far fa-mobile-screen-button', $receipt, $fresh, 'display_mode', [
                'standalone' => ['active', 'Als Web-App geöffnet'], 'browser' => ['info', 'Im Browser geöffnet'],
            ], 'Der Anzeigemodus meldet, wie RailTime auf diesem Gerät geöffnet wurde. Ein Browserfenster beweist keine Deinstallation.'),
            'push' => $this->runtimeRow('Push-Freigabe', 'far fa-bell', $receipt, $fresh, 'notification_permission', [
                'granted' => ['ready', 'Push im Browser erlaubt'], 'denied' => ['denied', 'Push im Browser blockiert'],
                'default' => ['info', 'Push im Browser noch nicht entschieden'], 'unsupported' => ['unknown', 'Push auf dieser Plattform nicht unterstützt'],
            ], 'Die Browserberechtigung ist von der RailTime-Sitzungsfreigabe getrennt. Tatsächlicher Push-Versand benötigt zusätzlich die Freigabe und eine aktive Push-Anmeldung auf diesem Gerät.'),
            'location' => $this->runtimeRow('Standortberechtigung', 'far fa-location-dot', $receipt, $fresh, 'geolocation_permission', [
                'granted' => ['ready', 'Standort im Browser erlaubt'], 'denied' => ['denied', 'Standort im Browser blockiert'],
                'prompt' => ['info', 'Standort im Browser noch nicht entschieden'], 'unsupported' => ['unknown', 'Standortberechtigung auf dieser Plattform nicht auslesbar'],
            ], 'Dies ist die Browserberechtigung, kein Nachweis einer laufenden Ortung. Arbeitsstandorte benötigen außerdem die RailTime-Freigabe, das zugeordnete Geschäftshandy, den nativen Standortdienst und dessen ausdrücklichen Start während laufender Arbeitszeit.'),
        ];

        return $result;
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

    private function runtimeRow(string $label, string $icon, UserAppRuntimeReceipt $receipt, bool $fresh, string $field, array $labels, string $explanation): array
    {
        [$state, $value] = $labels[$receipt->{$field}] ?? ['unknown', 'Nicht gemeldet'];
        $reportedAt = $receipt->received_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i');

        return ['label' => $label, 'icon' => $icon, 'state' => $fresh ? $state : 'unknown',
            'value' => $fresh ? $value : 'Zuletzt gemeldet: '.$value,
            'evidence' => 'Meldung vom '.$reportedAt.' Uhr. '.($fresh ? '' : 'Älter als 15 Minuten; aktueller Zustand unbekannt. ').$explanation];
    }
}
