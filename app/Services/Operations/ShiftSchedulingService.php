<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ShiftSchedulingService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function save(Shift $shift, array $attributes, User $actor, array $context = []): Shift
    {
        OperationsAccess::authorize($actor, 'operations.manage');

        return OperationsTransaction::run(function () use ($shift, $attributes, $actor, $context): Shift {
            OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
            $userIds = $shift->exists ? $shift->assignments()->blocking()->pluck('user_id')->all() : [];
            PlanningLocks::acquire($shift->exists ? [$shift->id] : [], $userIds, [(int) ($attributes['order_id'] ?? $shift->order_id)]);
            $persistedShift = $shift->exists
                ? Shift::query()->lockForUpdate()->findOrFail($shift->getKey())
                : $shift;

            $native = OperationsAccess::ready();
            $expected = $attributes['expected_revision'] ?? null;
            unset($attributes['expected_revision']);
            if ($native && $persistedShift->exists) {
                // Keep the last released employee-facing schedule while editing a draft.
                if ($persistedShift->published_revision > 0 && ! $persistedShift->published_snapshot) {
                    $persistedShift->published_snapshot = $persistedShift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
                }
                if ($expected !== null && (int) $expected !== $persistedShift->revision) {
                    throw ValidationException::withMessages(['workflow' => 'Schicht wurde geändert. Bitte neu laden.']);
                }
                if (WorkTimeEntry::whereHas('assignment', fn ($q) => $q->where('shift_id', $persistedShift->id))->exists()) {
                    throw ValidationException::withMessages(['workflow' => 'Für diese Schicht wurden bereits Zeiten erfasst.']);
                }
            }

            $startsAt = $attributes['starts_at'] ?? $persistedShift->starts_at;
            $endsAt = $attributes['ends_at'] ?? $persistedShift->ends_at;
            $status = $attributes['status'] ?? $persistedShift->status ?? ShiftStatus::Draft;
            $shiftStatus = $status instanceof ShiftStatus ? $status : ShiftStatus::tryFrom((string) $status);

            if (! $startsAt instanceof CarbonInterface || ! $endsAt instanceof CarbonInterface || $endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages([
                    'endsAt' => 'Das Schichtende muss nach dem Schichtbeginn liegen.',
                ]);
            }

            if ($shiftStatus === null) {
                throw ValidationException::withMessages([
                    'status' => 'Der angegebene Schichtstatus ist ungültig.',
                ]);
            }

            $orderId = (int) ($attributes['order_id'] ?? $persistedShift->order_id);
            $order = Order::withTrashed()->lockForUpdate()->find($orderId);
            $keepsExistingOrder = $persistedShift->exists && (int) $persistedShift->order_id === $orderId;
            $orderIsUnavailable = $order !== null && ($order->trashed() || $order->status->value === 'cancelled');

            if ($order === null || ($orderIsUnavailable && (! $keepsExistingOrder || $shiftStatus !== ShiftStatus::Cancelled))) {
                throw ValidationException::withMessages([
                    'orderId' => 'Die Schicht kann nur einem aktiven, nicht stornierten Auftrag zugeordnet werden.',
                ]);
            }

            if ($shiftStatus !== ShiftStatus::Cancelled
                && ($startsAt->lessThan($order->starts_at) || $endsAt->greaterThan($order->ends_at))) {
                throw ValidationException::withMessages([
                    'startsAt' => sprintf(
                        'Die Schicht muss innerhalb des Auftragszeitraums (%s bis %s) liegen.',
                        $order->starts_at->format('d.m.Y H:i'),
                        $order->ends_at->format('d.m.Y H:i'),
                    ),
                ]);
            }

            if ($persistedShift->exists && $shiftStatus !== ShiftStatus::Cancelled) {
                $this->assertAssignedEmployeesRemainAvailable($persistedShift, $startsAt, $endsAt);
            }

            $persistedShift->fill($attributes);
            $persistedShift->status = $shiftStatus;
            if ($shiftStatus !== ShiftStatus::Cancelled && $persistedShift->exists
                && $persistedShift->assignments()->blocking()->count() > $persistedShift->required_staff) {
                throw ValidationException::withMessages(['workflow' => 'Personalzahl kann nicht unter die reservierten Einsatzplätze sinken.']);
            }
            $persistedShift->updated_by = $actor->getKey();
            if ($persistedShift->order_demand_id && $shiftStatus !== ShiftStatus::Cancelled) {
                $demand = OrderDemand::findOrFail($persistedShift->order_demand_id);
                if ($demand->status !== 'active' || $persistedShift->order_id !== $demand->order_id || $persistedShift->role_name !== $demand->role_name
                    || $startsAt->lt($demand->starts_at) || $endsAt->gt($demand->ends_at)) {
                    throw ValidationException::withMessages(['workflow' => 'Dienst muss zu Auftrag, Tätigkeit und Zeitraum des verknüpften Bedarfs passen.']);
                }
                app(OrderDemandService::class)->assertPlannedCapacity($persistedShift, $context, true);
            }
            if ($native && $persistedShift->exists && $shiftStatus !== ShiftStatus::Cancelled) {
                app(DutyActivityService::class)->validateSections($persistedShift);
            }
            if ($shiftStatus !== ShiftStatus::Cancelled && class_exists(PlanningEnhancementService::class)) {
                $issues = app(PlanningEnhancementService::class)->scheduleIssues($persistedShift);
                if ($issues !== []) {
                    throw ValidationException::withMessages(['workflow' => collect($issues)->pluck('message')->implode(' ')]);
                }
            }

            if (! $persistedShift->exists) {
                $persistedShift->created_by = $actor->getKey();
                if ($native) {
                    $persistedShift->revision = 1;
                    $persistedShift->published_revision = 0;
                }
            }

            if ($native && $persistedShift->exists && $persistedShift->isDirty()) {
                $persistedShift->revision++;
                if ($shiftStatus !== ShiftStatus::Cancelled) {
                    foreach ($persistedShift->assignments()->blocking()->with('user')->get() as $assignment) {
                        app(StaffEligibilityService::class)->assertEligible($persistedShift, $assignment->user);
                    }
                }
            }

            $persistedShift->save();
            if ($native) {
                app(OperationsAuditService::class)->record($persistedShift, $actor, 'shift.saved');
            }

            return $persistedShift->load(['order.customer', 'assignments.user']);
        }, 3);
    }

    private function assertAssignedEmployeesRemainAvailable(
        Shift $shift,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): void {
        $userIds = ShiftAssignment::query()
            ->where('shift_id', $shift->getKey())
            ->whereIn('status', ShiftAssignmentStatus::blockingValues())
            ->lockForUpdate()
            ->pluck('user_id')
            ->unique()
            ->sort()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereKey($userIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $conflictingAssignment = ShiftAssignment::query()
            ->blocking()
            ->whereIn('user_id', $userIds->all())
            ->where('shift_id', '!=', $shift->getKey())
            ->whereHas('shift', function ($query) use ($startsAt, $endsAt): void {
                $query
                    ->notCancelled()
                    ->during($startsAt, $endsAt);
            })
            ->with(['user', 'shift.order'])
            ->lockForUpdate()
            ->first();

        if ($conflictingAssignment === null) {
            return;
        }

        throw ValidationException::withMessages([
            'startsAt' => sprintf(
                '%s ist in diesem Zeitraum bereits für „%s“ eingeplant (%s bis %s).',
                $conflictingAssignment->user?->name ?? 'Der ausgewählte Mitarbeiter',
                $conflictingAssignment->shift->title,
                $conflictingAssignment->shift->starts_at->format('d.m.Y H:i'),
                $conflictingAssignment->shift->ends_at->format('d.m.Y H:i'),
            ),
        ]);
    }
}
