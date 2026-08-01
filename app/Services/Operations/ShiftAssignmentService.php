<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftAssignmentService
{
    public function assign(
        Shift $shift,
        User $assignee,
        User $actor,
        ShiftAssignmentStatus|string $status = ShiftAssignmentStatus::Confirmed,
        ?string $note = null,
    ): ShiftAssignment {
        $assignmentStatus = $this->normalizeStatus($status);

        return DB::transaction(function () use ($shift, $assignee, $actor, $assignmentStatus, $note): ShiftAssignment {
            $lockedShift = Shift::query()
                ->with('order.customer')
                ->lockForUpdate()
                ->findOrFail($shift->getKey());

            $lockedAssignee = User::query()
                ->lockForUpdate()
                ->findOrFail($assignee->getKey());

            $this->assertValidSchedule($lockedShift);
            $this->assertAssigneeCanBeScheduled($lockedAssignee);

            if ($assignmentStatus->blocksAvailability()) {
                $this->assertShiftCanBeStaffed($lockedShift);
                $this->assertNoOverlap($lockedShift, $lockedAssignee);
            }

            $assignment = ShiftAssignment::query()
                ->where('shift_id', $lockedShift->getKey())
                ->where('user_id', $lockedAssignee->getKey())
                ->lockForUpdate()
                ->first() ?? new ShiftAssignment;

            $assignment->fill([
                'shift_id' => $lockedShift->getKey(),
                'user_id' => $lockedAssignee->getKey(),
                'status' => $assignmentStatus,
                'assigned_by' => $actor->getKey(),
                'responded_at' => $assignmentStatus === ShiftAssignmentStatus::Requested ? null : now(),
                'note' => $note,
            ])->save();

            return $assignment->load(['shift.order.customer', 'user', 'assigner']);
        });
    }

    public function cancel(
        ShiftAssignment $assignment,
        User $actor,
        ?string $note = null,
    ): ShiftAssignment {
        return DB::transaction(function () use ($assignment, $actor, $note): ShiftAssignment {
            $lockedShift = Shift::query()
                ->with('order.customer')
                ->lockForUpdate()
                ->findOrFail($assignment->shift_id);

            $lockedAssignee = User::query()
                ->lockForUpdate()
                ->findOrFail($assignment->user_id);

            $lockedAssignment = ShiftAssignment::query()
                ->whereKey($assignment->getKey())
                ->where('shift_id', $lockedShift->getKey())
                ->where('user_id', $lockedAssignee->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAssignment === null) {
                throw ValidationException::withMessages([
                    'user_id' => 'Für diesen Mitarbeiter besteht in der Schicht keine Zuweisung.',
                ]);
            }

            $lockedAssignment->forceFill([
                'status' => ShiftAssignmentStatus::Cancelled,
                'assigned_by' => $actor->getKey(),
                'responded_at' => now(),
                'note' => $note ?? $lockedAssignment->note,
            ])->save();

            return $lockedAssignment->load(['shift.order.customer', 'user', 'assigner']);
        });
    }

    public function unassign(
        Shift $shift,
        User $assignee,
        User $actor,
        ?string $note = null,
    ): ShiftAssignment {
        $assignment = ShiftAssignment::query()
            ->where('shift_id', $shift->getKey())
            ->where('user_id', $assignee->getKey())
            ->first();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Für diesen Mitarbeiter besteht in der Schicht keine Zuweisung.',
            ]);
        }

        return $this->cancel($assignment, $actor, $note);
    }

    private function assertValidSchedule(Shift $shift): void
    {
        if ($shift->ends_at->lessThanOrEqualTo($shift->starts_at)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Das Schichtende muss nach dem Schichtbeginn liegen.',
            ]);
        }
    }

    private function assertShiftCanBeStaffed(Shift $shift): void
    {
        if ($shift->status === ShiftStatus::Cancelled) {
            throw ValidationException::withMessages([
                'shift_id' => 'Eine stornierte Schicht kann nicht besetzt werden.',
            ]);
        }
    }

    private function assertAssigneeCanBeScheduled(User $assignee): void
    {
        if (! $assignee->status || $assignee->role !== 'staff') {
            throw ValidationException::withMessages([
                'user_id' => 'Nur aktive Mitarbeiter können einer Schicht zugewiesen werden.',
            ]);
        }
    }

    private function assertNoOverlap(Shift $shift, User $assignee): void
    {
        $conflictingAssignment = ShiftAssignment::query()
            ->blocking()
            ->where('user_id', $assignee->getKey())
            ->where('shift_id', '!=', $shift->getKey())
            ->whereHas('shift', function ($query) use ($shift): void {
                $query
                    ->notCancelled()
                    ->during($shift->starts_at, $shift->ends_at);
            })
            ->with('shift.order')
            ->lockForUpdate()
            ->first();

        if ($conflictingAssignment === null) {
            return;
        }

        $conflictingShift = $conflictingAssignment->shift;

        throw ValidationException::withMessages([
            'user_id' => sprintf(
                '%s ist bereits von %s bis %s für „%s“ eingeplant.',
                $assignee->name,
                $conflictingShift->starts_at->format('d.m.Y H:i'),
                $conflictingShift->ends_at->format('d.m.Y H:i'),
                $conflictingShift->title,
            ),
        ]);
    }

    private function normalizeStatus(ShiftAssignmentStatus|string $status): ShiftAssignmentStatus
    {
        if ($status instanceof ShiftAssignmentStatus) {
            return $status;
        }

        $normalized = ShiftAssignmentStatus::tryFrom($status);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'status' => 'Der angegebene Zuweisungsstatus ist ungültig.',
            ]);
        }

        return $normalized;
    }
}
