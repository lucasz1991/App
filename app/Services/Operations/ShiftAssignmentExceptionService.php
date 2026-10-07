<?php

namespace App\Services\Operations;

use App\Enums\ShiftStatus;
use App\Models\EmployeeRuleAssignment;
use App\Models\EmployeeWorkModel;
use App\Models\OperationAudit;
use App\Models\OperationsRateRule;
use App\Models\OperationsRuleProfile;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** A documented planning exception, never a legal exemption or a reusable eligibility waiver. */
class ShiftAssignmentExceptionService
{
    public const TEMPORAL_CODES = [
        'rest_overlap', 'transfer_buffer', 'transfer_buffer_outgoing', 'shift_duration', 'break_missing',
        'contract_workday', 'contract_window', 'contract_weekly_limit',
        'configured_rolling_minutes', 'configured_consecutive_days', 'configured_night_count',
        'training_rest_before', 'training_rest_after',
    ];

    public function canOverride(User $actor): bool
    {
        return $actor->status
            && in_array($actor->dashboardAudience(), ['admin', 'administration', 'management'], true)
            && Gate::forUser($actor)->allows('operations.manage');
    }

    public function isTemporalIssue(array $issue): bool
    {
        return in_array($issue['code'] ?? '', self::TEMPORAL_CODES, true);
    }

    /** Mutation callers must first acquire PlanningLocks for this shift and employee. */
    public function review(Shift $shift, User $user, User $actor, bool $lock = false): array
    {
        return $this->inspect($shift, $user, $actor, $lock);
    }

    private function inspect(Shift $shift, User $user, User $actor, bool $lock, bool $existingAssignment = false): array
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.manage');
        OperationsAccess::requireReady();
        $query = fn ($builder) => $lock ? $builder->lockForUpdate() : $builder;
        $shift = $query(Shift::whereKey($shift->id))->firstOrFail();
        $user = $query(User::whereKey($user->id))->firstOrFail();
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);

        // Snapshot persisted inputs, not a guessed statutory/default profile. Sorted rows keep reviews stable.
        $profiles = $query(OperationsRuleProfile::orderBy('id'))->get();
        $accounts = app(WorkforceAccountService::class);
        $profile = $accounts->ready() ? $accounts->effectiveRules($user, $start) : $profiles->firstWhere('is_active', true);
        $rules = [
            'profile_id' => $profile?->id,
            'profile_name' => $profile?->name,
            'minimum_rest_minutes' => $profile?->minimum_rest_minutes,
            'maximum_shift_minutes' => $profile?->maximum_shift_minutes,
            'break_after_minutes' => $profile?->break_after_minutes,
            'minimum_break_minutes' => $profile?->minimum_break_minutes,
            'planned_break_minutes' => (int) $shift->planned_break_minutes,
            'shift_minutes' => (int) $start->diffInMinutes($end),
            'transfer_buffer_minutes' => (int) ($shift->disposition_details['transfer_buffer_minutes'] ?? 0),
        ];
        $workModels = $accounts->ready()
            ? $query(EmployeeWorkModel::where('user_id', $user->id)->where('status', 'active')->orderBy('id'))->get()->map->only(['id', 'starts_on', 'ends_on', 'timezone', 'daily_minutes', 'work_windows', 'maximum_weekly_minutes', 'revision'])->all()
            : [];
        $ruleAssignments = $accounts->ready()
            ? $query(EmployeeRuleAssignment::where('user_id', $user->id)->orderBy('id'))->get()->map->only(['id', 'operations_rule_profile_id', 'starts_on', 'ends_on'])->all()
            : [];
        $rateRules = app(OperationsRuleEvaluationService::class)->ready()
            ? $query(OperationsRateRule::where('kind', 'planning')->whereNotNull('approved_at')->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))->orderBy('id'))->get()->map->only(['id', 'name', 'starts_on', 'ends_on', 'configuration', 'revision'])->all()
            : [];

        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), $lock)[$user->id];
        if ($end->lessThanOrEqualTo($start)) {
            $issues[] = ['code' => 'invalid_schedule', 'message' => 'Das Schichtende muss nach dem Schichtbeginn liegen.'];
        }
        if (in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true)) {
            $issues[] = ['code' => 'shift_closed', 'message' => 'Diese Schicht kann nicht mehr besetzt werden.'];
        }
        $alreadyAssigned = $query($shift->assignments()->blocking()->where('user_id', $user->id))->exists();
        if ($alreadyAssigned && ! $existingAssignment) {
            $issues[] = ['code' => 'already_assigned', 'message' => 'Mitarbeiter ist dieser Schicht bereits zugewiesen.'];
        }
        try {
            app(ShiftAssignmentService::class)->assertCapacity($shift, $user);
            if (! in_array('region_no_go', array_column($issues, 'code'), true)) {
                app(StaffRegionalPreferenceService::class)->assertAllowed($shift, $user, $lock);
            }
        } catch (ValidationException $exception) {
            $issues[] = ['code' => 'assignment_constraint', 'message' => $exception->validator->errors()->first()];
        }

        // The largest configurable planning window is 90 days. Include both sides for successor limits.
        $lookaround = max(90 * 24 * 60, (int) $profiles->max('minimum_rest_minutes'), $rules['transfer_buffer_minutes']);
        $assignments = $query(ShiftAssignment::blocking()->where('user_id', $user->id)->where('shift_id', '!=', $shift->id)
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($start->subMinutes($lookaround), $end->addMinutes($lookaround)))
            ->orderBy('id'))->with('shift')->get();
        $assignmentChanges = OperationAudit::where('subject_type', 'ShiftAssignment')->whereIn('subject_id', $assignments->pluck('id'))
            ->whereIn('action', ['assignment.saved', 'assignment.cancelled'])->selectRaw('subject_id, MAX(id) as change_id')->groupBy('subject_id')->pluck('change_id', 'subject_id');
        $basis = $assignments->map(fn ($assignment) => [
            'assignment_id' => $assignment->id,
            'status' => 'blocking', // Requested -> confirmed does not change the reserved interval.
            'last_change_id' => $assignmentChanges->get($assignment->id),
            'plan_revision' => $assignment->plan_revision,
            'shift' => $this->shiftSnapshot($assignment->shift),
        ])->all();
        $conflicts = [];
        foreach ($assignments as $assignment) {
            $detail = $this->conflict($shift, $assignment->shift, (int) $rules['minimum_rest_minutes']);
            if ($detail) {
                $conflicts[] = ['source' => 'shift', 'assignment_id' => $assignment->id] + $detail;
            }
        }
        $reservations = app(CustomerCapacityService::class)->additionalShifts($shift, $user);
        foreach ($reservations as $reservation) {
            $basis[] = ['reservation' => $this->shiftSnapshot($reservation)];
            $detail = $this->conflict($shift, $reservation, (int) $rules['minimum_rest_minutes']);
            if ($detail) {
                $conflicts[] = ['source' => 'reservation'] + $detail;
            }
        }
        if (app(PersonnelProcessService::class)->ready()) {
            $participants = $query(PersonnelTrainingParticipant::where('user_id', $user->id)->whereIn('status', ['confirmed', 'attended'])
                ->whereHas('training', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<', $end->addMinutes($lookaround)->utc())->where('ends_at', '>', $start->subMinutes($lookaround)->utc()))->orderBy('id'))->with('training')->get();
            foreach ($participants as $participant) {
                $training = $participant->training;
                $other = new Shift(['title' => $training->title, 'starts_at' => $training->starts_at, 'ends_at' => $training->ends_at, 'timezone' => $training->timezone, 'location_name' => $training->location_name]);
                $basis[] = ['training_id' => $training->id, 'participant_id' => $participant->id, 'status' => $participant->status, 'schedule' => $this->shiftSnapshot($other)];
                $otherProfile = $accounts->ready() ? $accounts->effectiveRules($user, $training->starts_at) : $profile;
                $detail = $this->conflict($shift, $other, max((int) $rules['minimum_rest_minutes'], (int) $otherProfile?->minimum_rest_minutes));
                if ($detail) {
                    $conflicts[] = ['source' => 'training', 'training_id' => $training->id] + $detail;
                }
            }
        }
        $temporal = array_values(array_filter($issues, fn ($issue) => $this->isTemporalIssue($issue)));
        $blocking = array_values(array_filter($issues, fn ($issue) => ! $this->isTemporalIssue($issue)));
        $snapshot = [
            'actor_id' => $actor->id, 'employee_id' => $user->id, 'shift' => $this->shiftSnapshot($shift),
            'issues' => $issues, 'rules' => $rules, 'conflicts' => $conflicts, 'basis' => $basis,
            'profiles' => $profiles->map->only(['id', 'name', 'is_active', 'minimum_rest_minutes', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes', 'approved_at'])->all(),
            'work_models' => $workModels, 'rule_assignments' => $ruleAssignments, 'rate_rules' => $rateRules,
        ];

        return [
            'issues' => $issues, 'temporal_issues' => $temporal, 'blocking_issues' => $blocking,
            'can_override' => $this->canOverride($actor) && $temporal !== [] && $blocking === [],
            'fingerprint' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key')),
            'conflicts' => $conflicts, 'rules' => $rules, 'shift' => $snapshot['shift'],
            'employee_id' => $user->id, 'actor_id' => $actor->id,
        ];
    }

    public function confirm(Shift $shift, User $user, User $actor, array $confirmation): array
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($this->canOverride($actor), 403);
        $reason = is_string($confirmation['reason'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $confirmation['reason']) ?? '') : '';
        if (($confirmation['acknowledged'] ?? null) !== true || mb_strlen($reason) < 20 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['workflow' => 'Ausnahme ausdrücklich bestätigen und eine konkrete Begründung mit 20 bis 2.000 Zeichen angeben.']);
        }
        $review = $this->review($shift, $user, $actor, true);
        if (! $review['can_override']) {
            throw ValidationException::withMessages(['workflow' => $review['blocking_issues'][0]['message'] ?? 'Keine freigabefähige zeitliche Ausnahme. Bitte die Auswahl erneut prüfen.']);
        }
        if (! is_string($confirmation['fingerprint'] ?? null) || ! hash_equals($review['fingerprint'], $confirmation['fingerprint'])) {
            throw ValidationException::withMessages(['workflow' => 'Planungsgrundlagen haben sich geändert. Warnungen erneut prüfen und die Ausnahme neu bestätigen.']);
        }

        return $review + ['reason' => $reason, 'acknowledged' => true, 'legal_exemption' => false];
    }

    /** Publish/response only: honor a current audited decision, not a blanket or transferable waiver. */
    public function assertEligibleForAssignment(Shift $shift, User $user, ShiftAssignment $assignment): void
    {
        app(StaffRegionalPreferenceService::class)->assertAllowed($shift, $user, true);
        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), true)[$user->id];
        if ($issues === []) {
            return;
        }
        $reject = function () use ($issues): never {
            throw ValidationException::withMessages(['workflow' => $issues[0]['message'].' Eine aktuelle, ausdrücklich bestätigte Ausnahmefreigabe ist erforderlich.']);
        };
        if (! OperationsAccess::ready() || ! $assignment->status->blocksAvailability()
            || (int) $assignment->shift_id !== (int) $shift->id || (int) $assignment->user_id !== (int) $user->id
            || collect($issues)->contains(fn ($issue) => ! $this->isTemporalIssue($issue))) {
            $reject();
        }
        if ($this->currentDecision($shift, $user, $assignment) !== null) {
            return;
        }

        // Approval of B explicitly shows its already-reserved A. It may cover A's later
        // response too, but only when B alone causes A's issues; never chain approvals.
        $lookaround = max(90 * 24 * 60, (int) OperationsRuleProfile::max('minimum_rest_minutes'));
        $peers = ShiftAssignment::blocking()->where('user_id', $user->id)->where('shift_id', '!=', $shift->id)
            ->whereHas('shift', fn ($query) => $query->notCancelled()->during($shift->starts_at->subMinutes($lookaround), $shift->ends_at->addMinutes($lookaround)))
            ->orderBy('id')->with('shift')->get();
        foreach ($peers as $peer) {
            if ($this->currentDecision($peer->shift, $user, $peer, $assignment->id) === null) {
                continue;
            }
            $withoutPeer = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), true, ['exclude_shift_ids' => [$peer->shift_id]])[$user->id];
            if ($withoutPeer === []) {
                return;
            }
        }
        $reject();
    }

    private function currentDecision(Shift $shift, User $user, ShiftAssignment $assignment, ?int $requiredConflictAssignment = null): ?array
    {
        $audit = OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignment->id)
            ->where('action', 'assignment.temporal_exception')->latest('id')->first();
        if (! $audit || ($audit->data['acknowledged'] ?? false) !== true
            || ($audit->data['legal_exemption'] ?? null) !== false
            || (int) ($audit->data['plan_revision'] ?? 0) !== (int) $shift->revision
            || (int) $assignment->plan_revision !== (int) $shift->revision
            || OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignment->id)
                ->whereIn('action', ['assignment.saved', 'assignment.cancelled'])->where('id', '>', $audit->id)->exists()) {
            return null;
        }
        if ($requiredConflictAssignment !== null && ! collect($audit->data['conflicts'] ?? [])->contains(
            fn ($conflict) => ($conflict['source'] ?? null) === 'shift' && (int) ($conflict['assignment_id'] ?? 0) === $requiredConflictAssignment
        )) {
            return null;
        }
        $approver = User::find($audit->actor_id);
        if (! $approver || ! $this->canOverride($approver)) {
            return null;
        }
        $review = $this->inspect($shift, $user, $approver, true, true);
        if (! $review['can_override'] || ! is_string($audit->data['fingerprint'] ?? null)
            || ! hash_equals($review['fingerprint'], $audit->data['fingerprint'])) {
            return null;
        }

        return $review;
    }

    private function shiftSnapshot(Shift $shift): array
    {
        return [
            'id' => $shift->id, 'revision' => $shift->revision, 'title' => $shift->title,
            'order_id' => $shift->order_id, 'role_name' => $shift->role_name,
            'starts_at' => $shift->starts_at->utc()->toIso8601String(), 'ends_at' => $shift->ends_at->utc()->toIso8601String(),
            'timezone' => $shift->timezone, 'location_name' => $shift->location_name,
            // Publishing a draft only changes its visibility, not the approved planning basis.
            'status' => in_array($shift->status, [ShiftStatus::Draft, ShiftStatus::Open], true) ? 'open' : $shift->status?->value,
            'required_staff' => $shift->required_staff,
            'planned_break_minutes' => $shift->planned_break_minutes,
            'transfer_buffer_minutes' => (int) ($shift->disposition_details['transfer_buffer_minutes'] ?? 0),
        ];
    }

    private function conflict(Shift $shift, Shift $other, int $rest): ?array
    {
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);
        $overlap = $other->starts_at->lt($end) && $other->ends_at->gt($start);
        $before = $other->ends_at->lte($start);
        $gap = $overlap ? 0 : (int) ($before ? $other->ends_at->diffInMinutes($start) : $end->diffInMinutes($other->starts_at));
        $sameLocation = filled($shift->location_name) && filled($other->location_name)
            && mb_strtolower(trim($shift->location_name)) === mb_strtolower(trim($other->location_name));
        $buffer = (int) (($before ? $shift : $other)->disposition_details['transfer_buffer_minutes'] ?? 0);
        if (! $overlap && $gap >= $rest && ($sameLocation || $gap >= $buffer)) {
            return null;
        }

        return [
            'shift_id' => $other->id, 'title' => $other->title, 'location_name' => $other->location_name,
            'starts_at' => $other->starts_at->setTimezone($shift->timezone)->toIso8601String(),
            'ends_at' => $other->ends_at->setTimezone($shift->timezone)->toIso8601String(),
            'type' => $overlap ? 'overlap' : ($gap < $rest ? 'rest' : 'transfer'),
            'direction' => $overlap ? 'simultaneous' : ($before ? 'before' : 'after'),
            'overlap_minutes' => $overlap ? (int) $start->max($other->starts_at)->diffInMinutes($end->min($other->ends_at)) : 0,
            'gap_minutes' => $overlap ? null : $gap, 'required_rest_minutes' => $rest,
            'transfer_buffer_minutes' => $buffer, 'same_location' => $sameLocation,
        ];
    }
}
