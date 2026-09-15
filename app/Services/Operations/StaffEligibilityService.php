<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use Illuminate\Validation\ValidationException;

class StaffEligibilityService
{
    public function assertEligible(Shift $shift, User $user): void
    {
        if (! OperationsAccess::ready()) {
            return;
        }
        $this->check($user->status && $user->role === 'staff', 'Mitarbeiter ist nicht einsatzberechtigt.');
        $profile = OperationsRuleProfile::where('is_active', true)->lockForUpdate()->first();
        $this->check($profile !== null, 'Regelprofil fehlt.');
        $minutes = $shift->starts_at->diffInMinutes($shift->ends_at);
        $this->check($minutes <= $profile->maximum_shift_minutes, 'Die Schicht überschreitet die freigegebene Höchstdauer.');
        $this->check($shift->planned_break_minutes < $minutes, 'Die Pause muss kürzer als die Schicht sein.');
        $this->check($minutes <= $profile->break_after_minutes || $shift->planned_break_minutes >= $profile->minimum_break_minutes, 'Die geplante Pause ist zu kurz.');
        $this->check(! AbsenceRequest::where('user_id', $user->id)->where('status', 'approved')
            ->where('starts_at', '<', $shift->ends_at->utc())->where('ends_at', '>', $shift->starts_at->utc())->lockForUpdate()->first(), 'Eine genehmigte Abwesenheit überschneidet sich mit der Schicht.');
        foreach ($shift->qualifications()->lockForUpdate()->get() as $type) {
            $this->check($type->is_active && EmployeeQualification::where('user_id', $user->id)->where('qualification_type_id', $type->id)
                ->where('status', 'approved')->whereDate('valid_from', '<=', $shift->starts_at->toDateString())
                ->whereDate('valid_until', '>=', $shift->ends_at->toDateString())->lockForUpdate()->first() !== null, 'Gültiger Nachweis fehlt: '.$type->name.'.');
        }
        $rest = $profile->minimum_rest_minutes;
        $conflict = ShiftAssignment::blocking()->where('user_id', $user->id)->where('shift_id', '!=', $shift->id)
            ->whereHas('shift', fn ($q) => $q->notCancelled()->during($shift->starts_at->subMinutes($rest), $shift->ends_at->addMinutes($rest)))->lockForUpdate()->first();
        $this->check(! $conflict, 'Schichtüberschneidung oder unterschrittene Ruhezeit.');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
