<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanPublicationService
{
    public function requirements(Shift $shift, int $revision, array $ids, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        Validator::make(['ids' => $ids], ['ids' => 'array|max:50', 'ids.*' => 'integer|distinct|exists:qualification_types,id'])->validate();
        $preflight = $this->preflight($shift);
        OperationsTransaction::run(function () use ($shift, $revision, $ids, $actor, $preflight) {
            OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
            PlanningLocks::acquire([$shift->id], $shift->assignments()->blocking()->pluck('user_id')->all());
            $record = Shift::lockForUpdate()->findOrFail($shift->id);
            $this->check($record->revision === $revision, 'Schicht wurde geändert. Bitte neu laden.');
            $this->check(! WorkTimeEntry::whereHas('assignment', fn ($q) => $q->where('shift_id', $record->id))->exists(), 'Für diese Schicht wurden bereits Zeiten erfasst.');
            $record->qualifications()->sync($ids);
            if ($record->published_revision > 0 && ! $record->published_snapshot) {
                $record->published_snapshot = $record->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'customer_location_id', 'location_snapshot', 'planned_break_minutes']);
            }
            foreach ($record->assignments()->blocking()->orderBy('user_id')->with('user')->get() as $assignment) {
                User::lockForUpdate()->findOrFail($assignment->user_id);
                app(StaffEligibilityService::class)->assertEligible($record, $assignment->user, ['travel_preflight' => $preflight]);
            }
            $record->revision++;
            $record->save();
            app(OperationsAuditService::class)->record($record, $actor, 'shift.requirements', ['qualification_ids' => $ids]);
        }, 3);
    }

    public function publish(Shift $shift, int $revision, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        $preflight = $this->preflight($shift);
        OperationsTransaction::run(function () use ($shift, $revision, $actor, $preflight) {
            OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
            PlanningLocks::acquire([$shift->id], $shift->assignments()->blocking()->pluck('user_id')->all());
            $record = Shift::lockForUpdate()->findOrFail($shift->id);
            $this->check($record->revision === $revision, 'Schicht wurde geändert. Bitte neu laden.');
            $this->check(! in_array($record->status->value, ['cancelled', 'completed'], true), 'Schicht kann nicht veröffentlicht werden.');
            $this->check($record->ends_at->isFuture(), 'Die Schicht liegt in der Vergangenheit.');
            app(SiteSnapshotService::class)->assertConfirmed($record);
            if ($record->published_revision === $revision) {
                return;
            }
            $changes = app(PlanChangeService::class)->changes($record);
            app(DutyActivityService::class)->validateSections($record);
            foreach ($record->assignments()->blocking()->orderBy('user_id')->get() as $assignment) {
                $user = User::lockForUpdate()->findOrFail($assignment->user_id);
                app(ShiftAssignmentExceptionService::class)->assertEligibleForAssignment($record, $user, $assignment, ['travel_preflight' => $preflight]);
                // Published changes require the employee to respond to the new revision.
                $assignment->forceFill(['status' => ShiftAssignmentStatus::Requested, 'plan_revision' => $revision, 'responded_at' => null])->save();
            }
            $record->forceFill(['published_revision' => $revision, 'published_at' => now()->utc(), 'published_snapshot' => $record->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'customer_location_id', 'location_snapshot', 'planned_break_minutes']) + ['sections' => app(DutyActivityService::class)->snapshot($record)], 'status' => $record->status->value === 'draft' ? 'open' : $record->status])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'shift.published', ['changes' => $changes, 'snapshot' => $record->published_snapshot, 'requirements' => $record->qualifications()->orderBy('name')->pluck('name')->implode(', ') ?: '—']);
        }, 3);
    }

    public function respond(int $assignmentId, int $revision, bool $accept, User $actor): void
    {
        $assignment = ShiftAssignment::findOrFail($assignmentId);
        OperationsAccess::own($actor, $assignment->user_id);
        $preflight = $accept ? $this->preflight(Shift::findOrFail($assignment->shift_id), collect([$actor])) : null;
        OperationsTransaction::run(function () use ($assignment, $revision, $accept, $actor, $preflight) {
            PlanningLocks::acquire([$assignment->shift_id], [$actor->id]);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $record = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            OperationsAccess::own(User::findOrFail($actor->id), $record->user_id);
            $this->check($shift->revision === $revision && $shift->published_revision === $revision && $record->plan_revision === $revision && $shift->status->value !== 'cancelled', 'Dienst wurde geändert. Bitte neu laden.');
            $this->check($record->status === ShiftAssignmentStatus::Requested, 'Dieser Dienst wurde bereits beantwortet.');
            if ($accept) {
                app(SiteSnapshotService::class)->assertConfirmed($shift);
                app(ShiftAssignmentExceptionService::class)->assertEligibleForAssignment($shift, $actor, $record, ['travel_preflight' => $preflight]);
            }
            $record->forceFill(['status' => $accept ? ShiftAssignmentStatus::Confirmed : ShiftAssignmentStatus::Declined, 'responded_at' => now()->utc()])->save();
            app(OperationsAuditService::class)->record($record, $actor, $accept ? 'assignment.accepted' : 'assignment.declined', ['plan_revision' => $revision]);
        }, 3);
    }

    private function preflight(Shift $shift, ?\Illuminate\Support\Collection $users = null): \App\Services\Routing\TravelPreflight
    {
        $users ??= User::whereIn('id', $shift->assignments()->blocking()->pluck('user_id'))->get();

        return app(\App\Services\Routing\TravelAssessmentService::class)->preflight($shift, $users);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
