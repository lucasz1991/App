<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\OrderDemand;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Dropbox\CompetencyRestrictions;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StaffEligibilityService
{
    public function assertEligible(Shift $shift, User $user, array $context = []): void
    {
        $issues = $this->assessMany($shift, collect([$user]), true, $context)[$user->id];
        if ($issues !== []) {
            throw ValidationException::withMessages(['workflow' => $issues[0]['message']]);
        }
    }

    /** @return array<int, array<int, array{code: string, message: string}>> */
    public function assessMany(Shift $shift, Collection $users, bool $lock = false, array $context = []): array
    {
        $results = $users->mapWithKeys(fn (User $user) => [$user->id => []])->all();
        if ($users->isEmpty() || ! OperationsAccess::ready()) {
            return $results;
        }
        // Preview and write guards share rules. Mutation callers hold shift/user locks.
        $query = fn ($builder) => $lock ? $builder->lockForUpdate() : $builder;
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);
        $profile = $query(OperationsRuleProfile::where('is_active', true))->first();
        $types = $shift->relationLoaded('qualifications') ? $shift->getRelation('qualifications') : $query($shift->qualifications())->get();
        $demand = $shift->order_demand_id && WorkforcePlanningSchema::demandsReady() ? $query(OrderDemand::whereKey($shift->order_demand_id))->first() : null;
        if ($demand) {
            $types = $types->merge($query(QualificationType::whereIn('id', $demand->qualification_ids ?? []))->get())->unique('id');
        }
        $ids = $users->pluck('id');
        $qualifications = $query(EmployeeQualification::whereIn('user_id', $ids)->where('status', 'approved')
            ->whereIn('qualification_type_id', $types->pluck('id'))
            ->whereDate('valid_from', '<=', $start->setTimezone($shift->timezone)->toDateString())
            ->whereDate('valid_until', '>=', $end->setTimezone($shift->timezone)->toDateString()))->get()->groupBy('user_id');
        $absent = $query(AbsenceRequest::whereIn('user_id', $ids)->where(fn ($q) => $q->where('status', 'approved')->orWhere(fn ($q) => $q->where('kind', 'sick')->where('status', 'reported')))
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()))->get()->keyBy('user_id');
        $accounts = class_exists(WorkforceAccountService::class) ? app(WorkforceAccountService::class) : null;
        $accountsReady = $accounts?->ready() ?? false;
        $individualProfiles = $users->mapWithKeys(fn ($user) => [$user->id => $accountsReady ? $accounts->effectiveRules($user, $start) : $profile]);
        $rest = max((int) ($profile?->minimum_rest_minutes ?? 0), (int) $individualProfiles->max('minimum_rest_minutes'));
        $excludes = array_unique(array_merge($context['exclude_shift_ids'] ?? [], $shift->id ? [$shift->id] : []));
        $lookaround = max($rest, (int) ($shift->disposition_details['transfer_buffer_minutes'] ?? 0), Schema::hasColumn('shifts', 'disposition_details') ? 10080 : 0);
        $conflicts = $query(ShiftAssignment::blocking()->whereIn('user_id', $ids)->whereNotIn('shift_id', $excludes)
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($start->subMinutes($lookaround), $end->addMinutes($lookaround))))->with('shift')->get()->groupBy('user_id');
        $minutes = $start->diffInMinutes($end);
        $externalRestrictions = app(CompetencyRestrictions::class)->forShift($shift, $users, $lock);
        foreach ($users as $user) {
            $profile = $individualProfiles->get($user->id);
            $issues = $externalRestrictions[$user->id] ?? [];
            $check = function (bool $ok, string $code, string $message) use (&$issues): void {
                if (! $ok) {
                    $issues[] = compact('code', 'message');
                }
            };
            $check($user->status && $user->role === 'staff', 'staff_inactive', 'Mitarbeiter ist nicht einsatzberechtigt.');
            $check($profile !== null, 'rules_missing', 'Regelprofil fehlt.');
            if ($profile) {
                $check($minutes <= $profile->maximum_shift_minutes, 'shift_duration', 'Die Schicht überschreitet die freigegebene Höchstdauer.');
                $check($shift->planned_break_minutes < $minutes, 'break_duration', 'Die Pause muss kürzer als die Schicht sein.');
                $check($minutes <= $profile->break_after_minutes || $shift->planned_break_minutes >= $profile->minimum_break_minutes, 'break_missing', 'Die geplante Pause ist zu kurz.');
            }
            $check(! $absent->has($user->id), 'absence', 'Eine genehmigte Abwesenheit überschneidet sich mit der Schicht.');
            foreach ($types as $type) {
                $check($type->is_active && $qualifications->get($user->id, collect())->contains('qualification_type_id', $type->id), 'qualification_'.$type->id, 'Gültiger Nachweis fehlt: '.$type->name.'.');
            }
            $otherShifts = $conflicts->get($user->id, collect())->pluck('shift')->merge(collect($context['additional_shifts'] ?? []));
            $rest = $profile?->minimum_rest_minutes ?? 0;
            $overlap = $otherShifts->contains(fn ($other) => $other !== $shift && $other->starts_at->lt($end->addMinutes($rest)) && $other->ends_at->gt($start->subMinutes($rest)));
            $check(! $overlap, 'rest_overlap', 'Schichtüberschneidung oder unterschrittene Ruhezeit.');
            $buffer = (int) ($shift->disposition_details['transfer_buffer_minutes'] ?? 0);
            if ($buffer > 0) {
                $arrivalConflict = $otherShifts->contains(fn ($other) => filled($other->location_name) && filled($shift->location_name) && $other->location_name !== $shift->location_name
                    && $other->ends_at->lte($start) && $other->ends_at->gt($start->subMinutes($buffer)));
                $check(! $arrivalConflict, 'transfer_buffer', 'Der gepflegte Anreise-/Ablösepuffer reicht nicht aus.');
            }
            if ($demand?->workforce_pool_id) {
                $pool = Schema::hasTable('workforce_pools') ? WorkforcePool::find($demand->workforce_pool_id) : null;
                $check($pool && $pool->is_active && Schema::hasTable('workforce_pool_user') && $pool->users()->whereKey($user->id)->exists(), 'pool', 'Mitarbeiter gehört nicht zum freigegebenen Bedarfspool.');
            }
            if ($accountsReady) {
                $issues = array_merge($issues, $accounts->planningIssues($shift, $user, $context));
            }
            if (class_exists(PersonnelProcessService::class)) {
                $issues = array_merge($issues, app(PersonnelProcessService::class)->planningIssues($shift, $user, $context));
                $issues = array_merge($issues, $this->shiftTrainingRestIssues($shift, $user, $context));
            }
            $outgoingConflict = $otherShifts->contains(fn ($other) => filled($other->location_name) && filled($shift->location_name) && $other->location_name !== $shift->location_name
                && $other->starts_at->gte($end) && $other->starts_at->lt($end->addMinutes((int) ($other->disposition_details['transfer_buffer_minutes'] ?? 0))));
            $check(! $outgoingConflict, 'transfer_buffer_outgoing', 'Anreise-/Ablösepuffer zum folgenden Dienst reicht nicht aus.');
            $results[$user->id] = $issues;
        }

        return $results;
    }

    /** Enrollment calls this after Training -> User locks, never locks a Shift in reverse order. */
    public function trainingRestIssues(PersonnelTraining $training, User $user, array $context = []): array
    {
        $profile = $this->rulesAt($user, CarbonImmutable::instance($training->starts_at));
        if (! $profile) {
            return [['code' => 'rules_missing', 'message' => 'Regelprofil für die Schulungsplanung fehlt.']];
        }
        $start = CarbonImmutable::instance($training->starts_at);
        $end = CarbonImmutable::instance($training->ends_at);
        $lookaround = $this->maximumConfiguredRest($profile);
        $others = ShiftAssignment::blocking()->where('user_id', $user->id)->whereNotIn('shift_id', $context['exclude_shift_ids'] ?? [])
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($start->subMinutes($lookaround), $end->addMinutes($lookaround)))
            ->with('shift')->get()->pluck('shift')->merge(collect($context['additional_shifts'] ?? []))
            ->unique(fn ($shift) => $shift->id ?: spl_object_id($shift));

        return $others->flatMap(fn ($shift) => $this->activityRestIssues($training, $shift, $user, $profile, 'Dienst'))->values()->all();
    }

    private function shiftTrainingRestIssues(Shift $shift, User $user, array $context): array
    {
        if (! app(PersonnelProcessService::class)->ready()) {
            return [];
        }
        $profile = $this->rulesAt($user, CarbonImmutable::instance($shift->starts_at));
        if (! $profile) {
            return []; // assessMany already marks missing shift rules as blocking.
        }
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);
        $lookaround = $this->maximumConfiguredRest($profile);
        $trainings = PersonnelTrainingParticipant::where('user_id', $user->id)->whereIn('status', ['confirmed', 'attended'])
            ->whereNotIn('personnel_training_id', $context['exclude_training_ids'] ?? [])
            ->whereHas('training', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<', $end->addMinutes($lookaround)->utc())->where('ends_at', '>', $start->subMinutes($lookaround)->utc()))
            ->with('training')->get()->pluck('training');

        return $trainings->flatMap(fn ($training) => $this->activityRestIssues($shift, $training, $user, $profile, 'Schulung'))->values()->all();
    }

    private function rulesAt(User $user, CarbonImmutable $at): ?OperationsRuleProfile
    {
        $accounts = app(WorkforceAccountService::class);

        return $accounts->ready() ? $accounts->effectiveRules($user, $at) : OperationsRuleProfile::where('is_active', true)->first();
    }

    private function maximumConfiguredRest(OperationsRuleProfile $profile): int
    {
        // Search bounds come only from persisted profiles; they are not a new legal/default rule.
        return max((int) $profile->minimum_rest_minutes, (int) OperationsRuleProfile::max('minimum_rest_minutes'));
    }

    private function activityRestIssues(Shift|PersonnelTraining $subject, Shift|PersonnelTraining $other, User $user, OperationsRuleProfile $profile, string $label): array
    {
        $start = CarbonImmutable::instance($subject->starts_at);
        $end = CarbonImmutable::instance($subject->ends_at);
        if ($other->starts_at->lt($end) && $other->ends_at->gt($start)) {
            return []; // Actual overlap has its existing dedicated hard guard, not a fabricated rest interval.
        }
        $otherProfile = $this->rulesAt($user, CarbonImmutable::instance($other->starts_at));
        if (! $otherProfile) {
            return [['code' => 'training_rules_missing', 'message' => 'Regelprofil für '.$label.' '.$other->title.' fehlt.']];
        }
        $rest = max((int) $profile->minimum_rest_minutes, (int) $otherProfile->minimum_rest_minutes);
        $before = $other->ends_at->lte($start);
        $gap = $before ? $other->ends_at->diffInSeconds($start) : $end->diffInSeconds($other->starts_at);
        if ($gap >= $rest * 60) {
            return [];
        }

        return [['code' => $before ? 'training_rest_before' : 'training_rest_after', 'message' => 'Konfigurierte Ruhezeit '.($before ? 'nach' : 'vor').' '.$label.' '.$other->title.' unterschritten ('.$rest.' min).']];
    }
}
