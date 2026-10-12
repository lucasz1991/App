<?php

namespace App\Livewire\Operations;

use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\PlanningTravelDisplay;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Operations\OperationsAccess;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftTravelPanel extends Component
{
    #[Locked]
    public int $shiftId;

    #[Locked]
    public int $revision;

    #[Locked]
    public array $employeeIds = [];

    #[Locked]
    public array $assessments = [];

    #[Locked]
    public array $geometry = [];

    #[Locked]
    public array $geometryCache = [];

    #[Locked]
    public bool $loaded = false;

    #[Locked]
    public bool $parentManaged = false;

    #[Locked]
    public array $geometryBasis = [];

    public function mount(int $shiftId, array $employeeIds, ?array $initialAssessments = null, bool $parentManaged = false): void
    {
        $this->shiftId = $shiftId;
        $shift = $this->shift();
        $this->revision = (int) $shift->revision;
        $this->employeeIds = array_slice(array_values(array_unique(array_map('intval', $employeeIds))), 0, 24);
        $this->parentManaged = $parentManaged;
        if ($initialAssessments !== null) {
            // Mount arguments come from the parent server render. Later requests retain
            // only signed, locked state; the current planning basis is still rechecked.
            $this->assessments = array_intersect_key($initialAssessments, array_flip($this->employeeIds));
            $this->loaded = true;
        }
    }

    private function shift(): Shift
    {
        $actor = auth()->user();
        $actor = $actor ? User::with('currentTeam')->find($actor->id) : null;
        abort_unless($actor, 403);
        OperationsAccess::authorize($actor, 'operations.manage');

        return Shift::notCancelled()->findOrFail($this->shiftId);
    }

    private function currentShift(): Shift
    {
        $shift = $this->shift();
        if ((int) $shift->revision !== $this->revision) {
            $this->revision = (int) $shift->revision;
            $this->assessments = [];
            $this->loaded = false;
            $this->clearGeometry();
            $this->addError('travel', 'Die Schicht wurde geändert. Bitte die Fahrten neu prüfen.');
        }

        return $shift;
    }

    public function load(): void
    {
        $shift = $this->currentShift();
        if ($this->parentManaged) {
            $this->dispatch('shift-travel-refresh-requested', shiftId: $this->shiftId);

            return;
        }
        $users = $this->users();
        $details = app(StaffEligibilityService::class)->assessDetailedMany($shift, $users, false, ['include_employee_location' => true, 'actor' => auth()->user()], true);
        $this->currentShift();
        $this->assessments = app(PlanningTravelDisplay::class)->stamp($details);
        $this->clearGeometry();
        $this->loaded = true;
        $this->resetValidation('travel');
    }

    public function showRoute(int $employeeId, string $direction): void
    {
        $shift = $this->currentShift();
        abort_unless(in_array($employeeId, $this->employeeIds, true) && in_array($direction, ['before', 'after', 'arrival'], true), 403);
        $user = User::where('role', 'staff')->where('status', true)->findOrFail($employeeId);
        $current = $this->refreshAssessments($shift, $this->users());
        $detail = $this->assessments[$employeeId] ?? [];
        $leg = $direction === 'arrival' ? ($detail['arrival'] ?? []) : ($detail['travel'][$direction] ?? []);
        if (($leg['status'] ?? null) !== 'known' || ($detail['display_state'] ?? null) === 'pending') {
            $this->clearGeometry();
            $this->addError('route', 'Bitte die Fahrten für die aktuelle Einsatzfolge zuerst neu prüfen.');

            return;
        }
        $basis = $current[$employeeId]['basis'] ?? '';
        $arrivalBasis = $direction === 'arrival' ? $this->arrivalIdentity($current[$employeeId]['arrival'] ?? []) : null;
        $result = app(TravelAssessmentService::class)->geometryFor($shift, $user, $direction, ['actor' => auth()->user()]);
        $fresh = $this->refreshAssessments($this->currentShift(), $this->users());
        if (! isset($fresh[$employeeId]) || ! hash_equals($basis, $fresh[$employeeId]['basis'] ?? '')
            || ($direction === 'arrival' && $arrivalBasis !== $this->arrivalIdentity($fresh[$employeeId]['arrival'] ?? []))) {
            $this->clearGeometry();
            $this->addError('route', 'Die Einsatzfolge oder Standortfreigabe wurde geändert. Bitte die Fahrten neu prüfen.');

            return;
        }
        $this->geometry = ($result['status'] ?? null) === 'known' ? ($result['points'] ?? []) : [];
        $this->geometryCache = $direction !== 'arrival' && ($result['cache_hit'] ?? false)
            ? array_intersect_key($result, array_flip(['cache_hit', 'calculated_at', 'expires_at'])) : [];
        if ($this->geometry === []) {
            $this->addError('route', 'Die Route ist aktuell nicht verfügbar. Die Zuteilung bleibt bedienbar.');
        } else {
            $this->resetValidation('route');
            $this->geometryBasis = ['employee_id' => $employeeId, 'direction' => $direction, 'basis' => $basis, 'arrival' => $arrivalBasis,
                'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'expires_at' => $result['expires_at'] ?? null];
        }
    }

    private function users(): Collection
    {
        return User::whereIn('id', $this->employeeIds)->where('role', 'staff')->where('status', true)->get();
    }

    private function clearGeometry(): void
    {
        $this->geometry = [];
        $this->geometryCache = [];
        $this->geometryBasis = [];
    }

    private function arrivalIdentity(array $arrival): ?string
    {
        if (empty($arrival['measured_at'])) {
            return null;
        }

        return hash('sha256', json_encode(array_intersect_key($arrival, array_flip(['basis', 'measured_at', 'accuracy_m'])), JSON_THROW_ON_ERROR));
    }

    /** No provider IO: hidden changes to either neighbor, rules or consent invalidate display data. */
    private function refreshAssessments(Shift $shift, Collection $users): array
    {
        $hasArrival = collect($this->assessments)->contains(fn ($detail) => isset($detail['arrival']));
        $current = app(TravelAssessmentService::class)->assessMany($shift, $users, ['skip_provider' => true, 'include_employee_location' => $hasArrival, 'actor' => auth()->user()]);
        $settings = TomTomSettings::all();
        $next = [];
        foreach ($users as $user) {
            $fresh = $current[$user->id];
            $saved = $this->assessments[$user->id] ?? [];
            $valid = $this->loaded && app(PlanningTravelDisplay::class)->reusable($fresh, $saved);
            $detail = $valid ? array_intersect_key($saved, array_flip(['issues', 'warnings', 'travel', 'arrival', 'basis', '_display_checked_at'])) : $fresh;
            if (isset($detail['arrival'])) {
                if (! $valid || $this->arrivalIdentity($detail['arrival']) !== $this->arrivalIdentity($fresh['arrival'] ?? []) || CarbonImmutable::parse($saved['_display_checked_at'])->addMinute()->lte(now())) {
                    $detail['arrival'] = $fresh['arrival'] ?? ['status' => 'unknown', 'reason' => 'point_unavailable'];
                }
                $reason = $detail['arrival']['reason'] ?? '';
                $detail['arrival']['reason_label'] = str_contains($reason, ' ') ? $reason : TravelAssessmentService::reasonLabel($reason);
            }
            $detail['display_state'] = ! $settings['enabled'] ? 'disabled' : (! $valid && ($fresh['travel'] ?? []) !== [] ? 'pending' : $this->displayState($detail));
            $next[$user->id] = $detail;
        }
        $this->assessments = $next;
        if ($this->geometryBasis !== []) {
            $id = $this->geometryBasis['employee_id'];
            $direction = $this->geometryBasis['direction'];
            $geometryFresh = false;
            try {
                $checked = CarbonImmutable::parse($this->geometryBasis['checked_at']);
                $minutes = $direction === 'arrival' ? 1 : max(1, min(60, (int) $settings['server_geometry_cache_minutes']));
                $geometryFresh = $checked->lte(now()) && $checked->addMinutes($minutes)->gt(now())
                    && (empty($this->geometryBasis['expires_at']) || CarbonImmutable::parse($this->geometryBasis['expires_at'])->gt(now()));
            } catch (\Throwable) {
                $geometryFresh = false;
            }
            if (! $geometryFresh || ! isset($next[$id]) || $next[$id]['display_state'] === 'pending' || ! hash_equals($this->geometryBasis['basis'], $next[$id]['basis'])
                || ($direction === 'arrival' && ($this->geometryBasis['arrival'] !== $this->arrivalIdentity($current[$id]['arrival'] ?? []) || ($next[$id]['arrival']['status'] ?? null) !== 'known'))) {
                $this->clearGeometry();
            }
        }

        return $current;
    }

    private function displayState(array $detail): string
    {
        if (collect($detail['issues'] ?? [])->contains(fn ($issue) => str_starts_with($issue['code'] ?? '', 'travel_'))) {
            return 'conflict';
        }
        if (($detail['travel'] ?? []) === []) {
            return 'no_transition';
        }

        return collect($detail['travel'])->contains(fn ($leg) => ($leg['status'] ?? null) !== 'known') ? 'unknown' : 'known';
    }

    public function render()
    {
        $shift = $this->currentShift();
        $users = $this->users();
        $this->refreshAssessments($shift, $users);

        return view('livewire.operations.shift-travel-panel', ['names' => $users->pluck('name', 'id'), 'shift' => $shift]);
    }
}
