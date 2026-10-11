<?php

namespace App\Services\EmployeeLocation;

use App\Models\DeviceAssignment;
use App\Models\EmployeeLocationConsent;
use App\Models\EmployeeLocationPoint;
use App\Models\MobileLocationInstallation;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PersonnelScopeService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EmployeeLocationReader
{
    public function latestForMany(Collection $users, array $context = []): array
    {
        $ids = $users->map(fn ($user) => $user instanceof User ? (int) $user->id : (int) $user)->unique()->values();
        $result = $ids->mapWithKeys(fn ($id) => [$id => $this->unavailable('disabled', 'Mitarbeiter-Standorte sind deaktiviert.')])->all();
        $settings = EmployeeLocationSettings::read();
        if (! EmployeeLocationSettings::ready() || ! $settings['enabled']) {
            return $result;
        }
        $actor = $context['actor'] ?? auth()->user();
        if (! $actor instanceof User || ! $actor->isActive()) {
            return $ids->mapWithKeys(fn ($id) => [$id => $this->unavailable('unauthorized', 'Standortzugriff ist nicht freigegeben.')])->all();
        }
        // Gate::before and User::hasRbacPermission grant administrators broad access.
        // Location viewing deliberately requires an explicit stored team permission too.
        $explicit = (bool) ($actor->currentTeam?->rbac_permissions['operations.locations.view'] ?? false);
        $scopeIds = $explicit ? app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.locations.view') : [];
        $allowed = $ids->filter(fn ($id) => $id === (int) $actor->id || ($explicit && ($scopeIds === null || in_array($id, $scopeIds, true))))->values();
        foreach ($ids->diff($allowed) as $id) {
            $result[$id] = $this->unavailable('unauthorized', 'Standortzugriff ist nicht freigegeben.');
        }
        if ($allowed->isEmpty()) {
            return $result;
        }
        $now = CarbonImmutable::now('UTC');
        $date = $now->setTimezone(EmployeeLocationService::ZONE)->toDateString();
        $subjects = User::whereIn('id', $allowed)->get()->keyBy('id');
        $consents = EmployeeLocationConsent::whereIn('user_id', $allowed)->where('local_date', $date)->where('purpose', 'work_routing')->get()->keyBy('user_id');
        $installations = MobileLocationInstallation::whereIn('user_id', $allowed)->whereNull('revoked_at')->get()->keyBy('user_id');
        $assignments = DeviceAssignment::whereIn('id', $installations->pluck('device_assignment_id'))->with('device')->get()->keyBy('id');
        $entries = WorkTimeEntry::whereIn('user_id', $allowed)->whereIn('status', ['running', 'paused'])->get()->keyBy('user_id');
        $points = EmployeeLocationPoint::whereIn('id', EmployeeLocationPoint::selectRaw('MAX(id)')->whereIn('user_id', $allowed)->groupBy('user_id'))->get()->keyBy('user_id');
        foreach ($allowed as $id) {
            $subject = $subjects->get($id);
            $consent = $consents->get($id);
            $installation = $installations->get($id);
            $entry = $entries->get($id);
            $point = $points->get($id);
            $assignment = $installation ? $assignments->get($installation->device_assignment_id) : null;
            if (! $subject?->isActive()) {
                $result[$id] = $this->unavailable('inactive', 'Mitarbeiterkonto ist inaktiv.');
            } elseif (! $consent || $consent->decision !== 'accepted' || $consent->withdrawn_at || $consent->expires_at->lte($now)
                || $consent->explanation_version !== (int) $settings['explanation_version'] || ! hash_equals($consent->explanation_hash, hash('sha256', $settings['explanation_text']))) {
                $result[$id] = $this->unavailable('consent_missing', 'Aktuelle Tagesfreigabe fehlt.');
            } elseif (! $installation || ! $assignment || $assignment->status !== 'active' || $assignment->returned_at || (int) $assignment->user_id !== $id
                || ! $assignment->device || $assignment->device->ownership !== 'corporate' || ! in_array($assignment->device->platform->value, ['android', 'ios'], true)
                || ! in_array($assignment->device->lifecycle_status->value, ['assigned', 'in_service'], true)
                || $consent->installation_id !== (int) $installation->id || $consent->device_assignment_id !== (int) $assignment->id) {
                $result[$id] = $this->unavailable('device_unavailable', 'Geschäftshandy ist nicht aktiv zugeordnet.');
            } elseif (! $entry || $entry->status !== 'running' || $entry->paused_at || $entry->ends_at || $entry->starts_at->gt($now)) {
                $result[$id] = $this->unavailable('not_working', 'Arbeitszeiterfassung läuft nicht oder ist pausiert.');
            } elseif (! $installation->session_id) {
                $result[$id] = $this->unavailable('service_stopped', 'Standortdienst wurde beendet.');
            } elseif (! $point || (int) $point->consent_id !== (int) $consent->id || (int) $point->installation_id !== (int) $installation->id
                || (int) $point->work_time_entry_id !== (int) $entry->id || $point->measured_at->lt($consent->accepted_at) || $point->measured_at->lt($installation->session_started_at)) {
                $result[$id] = $this->unavailable('no_point', 'Für die laufende Erfassung liegt kein Messpunkt vor.');
            } elseif ($point->measured_at->lt($now->subMinutes(15)) || $point->measured_at->gt($now->addSeconds(5))) {
                $result[$id] = $this->unavailable('stale', 'Letzter Messpunkt ist älter als 15 Minuten.');
            } elseif ($point->accuracy_m > 50) {
                $result[$id] = $this->unavailable('inaccurate', 'Letzter Messpunkt ist nicht präzise genug.');
            } else {
                $position = $point->position;
                $result[$id] = ['status' => 'available', 'reason' => null, 'point' => ['latitude' => (float) $position['latitude'], 'longitude' => (float) $position['longitude'], 'accuracy_m' => $point->accuracy_m, 'measured_at' => $point->measured_at->toIso8601String()]];
            }
        }

        return $result;
    }

    private function unavailable(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason, 'point' => null];
    }
}
