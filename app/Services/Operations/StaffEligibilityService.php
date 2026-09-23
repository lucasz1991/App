<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Dropbox\CompetencyRestrictions;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StaffEligibilityService
{
    public function assertEligible(Shift $shift, User $user): void
    {
        $issues = $this->assessMany($shift, collect([$user]), true)[$user->id];
        if ($issues !== []) {
            throw ValidationException::withMessages(['workflow' => $issues[0]['message']]);
        }
    }

    /** @return array<int, array<int, array{code: string, message: string}>> */
    public function assessMany(Shift $shift, Collection $users, bool $lock = false): array
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
        $types = $query($shift->qualifications())->get();
        $ids = $users->pluck('id');
        $qualifications = $query(EmployeeQualification::whereIn('user_id', $ids)->where('status', 'approved')
            ->whereIn('qualification_type_id', $types->pluck('id'))
            ->whereDate('valid_from', '<=', $start->setTimezone($shift->timezone)->toDateString())
            ->whereDate('valid_until', '>=', $end->setTimezone($shift->timezone)->toDateString()))->get()->groupBy('user_id');
        $absent = $query(AbsenceRequest::whereIn('user_id', $ids)->where('status', 'approved')
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()))->get()->keyBy('user_id');
        $rest = $profile?->minimum_rest_minutes ?? 0;
        $conflicts = $query(ShiftAssignment::blocking()->whereIn('user_id', $ids)->where('shift_id', '!=', $shift->id)
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($start->subMinutes($rest), $end->addMinutes($rest))))->get()->keyBy('user_id');
        $minutes = $start->diffInMinutes($end);
        $externalRestrictions = app(CompetencyRestrictions::class)->forShift($shift, $users, $lock);
        foreach ($users as $user) {
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
            $check(! $conflicts->has($user->id), 'rest_overlap', 'Schichtüberschneidung oder unterschrittene Ruhezeit.');
            $results[$user->id] = $issues;
        }

        return $results;
    }
}
