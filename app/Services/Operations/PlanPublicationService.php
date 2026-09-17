<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanPublicationService
{
    public function requirements(Shift $shift, int $revision, array $ids, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        Validator::make(['ids' => $ids], ['ids' => 'array|max:50', 'ids.*' => 'integer|distinct|exists:qualification_types,id'])->validate();
        DB::transaction(function () use ($shift, $revision, $ids, $actor) {
            $record = Shift::lockForUpdate()->findOrFail($shift->id);
            $this->check($record->revision === $revision, 'Schicht wurde geändert. Bitte neu laden.');
            $this->check(! WorkTimeEntry::whereHas('assignment', fn ($q) => $q->where('shift_id', $record->id))->exists(), 'Für diese Schicht wurden bereits Zeiten erfasst.');
            $record->qualifications()->sync($ids);
            if ($record->published_revision > 0 && ! $record->published_snapshot) {
                $record->published_snapshot = $record->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
            }
            foreach ($record->assignments()->blocking()->orderBy('user_id')->with('user')->get() as $assignment) {
                User::lockForUpdate()->findOrFail($assignment->user_id);
                app(StaffEligibilityService::class)->assertEligible($record, $assignment->user);
            }
            $record->revision++;
            $record->save();
            app(OperationsAuditService::class)->record($record, $actor, 'shift.requirements', ['qualification_ids' => $ids]);
        }, 3);
    }

    public function publish(Shift $shift, int $revision, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        DB::transaction(function () use ($shift, $revision, $actor) {
            $record = Shift::lockForUpdate()->findOrFail($shift->id);
            $this->check($record->revision === $revision, 'Schicht wurde geändert. Bitte neu laden.');
            $this->check(! in_array($record->status->value, ['cancelled', 'completed'], true), 'Schicht kann nicht veröffentlicht werden.');
            $this->check($record->ends_at->isFuture(), 'Die Schicht liegt in der Vergangenheit.');
            if ($record->published_revision === $revision) {
                return;
            }
            $changes = app(PlanChangeService::class)->changes($record);
            foreach ($record->assignments()->blocking()->orderBy('user_id')->get() as $assignment) {
                $user = User::lockForUpdate()->findOrFail($assignment->user_id);
                app(StaffEligibilityService::class)->assertEligible($record, $user);
                // Published changes require the employee to respond to the new revision.
                $assignment->forceFill(['status' => ShiftAssignmentStatus::Requested, 'plan_revision' => $revision, 'responded_at' => null])->save();
            }
            $record->forceFill(['published_revision' => $revision, 'published_at' => now()->utc(), 'published_snapshot' => $record->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']), 'status' => $record->status->value === 'draft' ? 'open' : $record->status])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'shift.published', ['changes' => $changes, 'snapshot' => $record->published_snapshot, 'requirements' => $record->qualifications()->orderBy('name')->pluck('name')->implode(', ') ?: '—']);
        }, 3);
    }

    public function respond(int $assignmentId, int $revision, bool $accept, User $actor): void
    {
        $assignment = ShiftAssignment::findOrFail($assignmentId);
        OperationsAccess::own($actor, $assignment->user_id);
        DB::transaction(function () use ($assignment, $revision, $accept, $actor) {
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $record = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            $this->check($shift->revision === $revision && $shift->published_revision === $revision && $record->plan_revision === $revision && $shift->status->value !== 'cancelled', 'Dienst wurde geändert. Bitte neu laden.');
            $this->check($record->status === ShiftAssignmentStatus::Requested, 'Dieser Dienst wurde bereits beantwortet.');
            if ($accept) {
                app(StaffEligibilityService::class)->assertEligible($shift, $actor);
            }
            $record->forceFill(['status' => $accept ? ShiftAssignmentStatus::Confirmed : ShiftAssignmentStatus::Declined, 'responded_at' => now()->utc()])->save();
            app(OperationsAuditService::class)->record($record, $actor, $accept ? 'assignment.accepted' : 'assignment.declined', ['plan_revision' => $revision]);
        }, 3);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
