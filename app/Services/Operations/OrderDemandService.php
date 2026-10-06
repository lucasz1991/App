<?php

namespace App\Services\Operations;

use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningSchema;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderDemandService
{
    public function save(int $orderId, ?int $id, ?int $revision, array $data, User $actor): OrderDemand
    {
        $this->access($actor);
        $rules = ['role_name' => 'required|string|max:160', 'required_staff' => 'required|integer|min:1|max:999', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone'];
        $extended = WorkforcePlanningSchema::ready();
        if ($extended) {
            $rules += ['staffing_mode' => 'sometimes|in:minimum,range,exact', 'maximum_staff' => 'nullable|integer|min:1|max:999', 'qualification_ids' => 'sometimes|array|max:50', 'qualification_ids.*' => 'integer|distinct|exists:qualification_types,id', 'workforce_pool_id' => 'nullable|integer|exists:workforce_pools,id'];
        }
        $data = Validator::make($data, $rules)->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return OperationsTransaction::run(function () use ($orderId, $id, $revision, $data, $start, $end, $actor, $extended) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $this->check($order->status->value !== 'cancelled' && $start->gte($order->starts_at) && $end->lte($order->ends_at), 'Bedarf muss in einem aktiven Auftragszeitraum liegen.');
            $demand = $id ? OrderDemand::where('order_id', $orderId)->lockForUpdate()->findOrFail($id) : new OrderDemand;
            $this->check(! $id || ($demand->revision === $revision && $demand->status === 'active'), 'Bedarf wurde geändert. Bitte neu laden.');
            if ($extended) {
                $data['staffing_mode'] = $data['staffing_mode'] ?? $demand->staffing_mode ?? 'minimum';
                $data['maximum_staff'] = $data['staffing_mode'] === 'exact' ? $data['required_staff'] : ($data['staffing_mode'] === 'minimum' ? null : ($data['maximum_staff'] ?? $demand->maximum_staff));
                $this->check($data['staffing_mode'] !== 'range' || ($data['maximum_staff'] !== null && $data['maximum_staff'] >= $data['required_staff']), 'Höchstbedarf muss mindestens dem Mindestbedarf entsprechen.');
                if (! empty($data['workforce_pool_id'])) {
                    $this->check(WorkforcePool::findOrFail($data['workforce_pool_id'])->is_active, 'Planungspool ist deaktiviert.');
                }
                $qualificationIds = $data['qualification_ids'] ?? $demand->qualification_ids ?? [];
                $this->check(QualificationType::whereKey($qualificationIds)->where('is_active', true)->count() === count($qualificationIds), 'Nur aktive Nachweisarten verwenden.');
                $data['qualification_ids'] = $qualificationIds;
            }
            if ($id && $demand->shifts()->notCancelled()->exists()) {
                $this->check($start->equalTo($demand->starts_at) && $end->equalTo($demand->ends_at) && $data['role_name'] === $demand->role_name && $data['timezone'] === $demand->timezone, 'Für geplante Bedarfe sind nur Personalzahlen änderbar. Zeit/Funktion über einen neuen Bedarf planen.');
            }
            $demand->fill(array_merge($data, ['order_id' => $orderId, 'starts_at' => $start, 'ends_at' => $end, 'revision' => $id ? $revision + 1 : 1, 'status' => 'active', 'created_by' => $id ? $demand->created_by : $actor->id]))->save();
            if ($extended) {
                $this->check($demand->maximum_staff === null || $this->coverage($demand)['peak_planned'] <= $demand->maximum_staff, 'Bereits geplante Einsatzplätze überschreiten den Höchstbedarf.');
                foreach ($demand->shifts()->notCancelled()->with('assignments.user')->get() as $shift) {
                    foreach ($shift->assignments->filter(fn ($a) => $a->status->blocksAvailability()) as $assignment) {
                        app(StaffEligibilityService::class)->assertEligible($shift, $assignment->user);
                    }
                }
            }
            app(OperationsAuditService::class)->record($demand, $actor, 'demand.saved');

            return $demand;
        }, 3);
    }

    public function coverage(OrderDemand $demand): array
    {
        $shifts = $demand->shifts()->notCancelled()->with('assignments')->get();
        $start = $demand->starts_at->timestamp;
        $end = $demand->ends_at->timestamp;
        $points = collect([$start, $end])->merge($shifts->flatMap(fn ($s) => [$s->starts_at->timestamp, $s->ends_at->timestamp]))->filter(fn ($t) => $t >= $start && $t <= $end)->unique()->sort()->values();
        $planned = $confirmed = $reserved = $requested = PHP_INT_MAX;
        $peakPlanned = $peakReserved = 0;
        $segments = [];
        foreach ($points->slice(0, -1) as $point) {
            $active = $shifts->filter(fn ($s) => $s->order_id === $demand->order_id && $s->role_name === $demand->role_name && $s->starts_at->timestamp <= $point && $s->ends_at->timestamp > $point);
            $planned = min($planned, $active->sum('required_staff'));
            $reservedNow = $active->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->blocksAvailability())->count());
            $requestedNow = $active->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->value === 'requested')->count());
            $reserved = min($reserved, $reservedNow);
            $requested = min($requested, $requestedNow);
            $peakPlanned = max($peakPlanned, $active->sum('required_staff'));
            $peakReserved = max($peakReserved, $reservedNow);
            $confirmed = min($confirmed, $active->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->value === 'confirmed' && $a->plan_revision === $s->published_revision && $s->published_revision === $s->revision)->count()));
            $segments[] = ['from' => CarbonImmutable::createFromTimestampUTC($point)->toIso8601String(), 'until' => CarbonImmutable::createFromTimestampUTC($points[$points->search($point) + 1])->toIso8601String(), 'planned' => $active->sum('required_staff'), 'reserved' => $reservedNow, 'requested' => $requestedNow, 'confirmed' => $active->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->value === 'confirmed' && $a->plan_revision === $s->published_revision && $s->published_revision === $s->revision)->count())];
        }

        $coverage = ['planned' => $planned === PHP_INT_MAX ? 0 : $planned, 'confirmed' => $confirmed === PHP_INT_MAX ? 0 : $confirmed,
            'open' => max(0, $demand->required_staff - ($planned === PHP_INT_MAX ? 0 : $planned))];

        return WorkforcePlanningSchema::ready() ? $coverage + ['reserved' => $reserved === PHP_INT_MAX ? 0 : $reserved, 'requested' => $requested === PHP_INT_MAX ? 0 : $requested, 'missing' => max(0, $demand->required_staff - $coverage['confirmed']), 'peak_planned' => $peakPlanned, 'peak_reserved' => $peakReserved, 'overstaffed' => $demand->maximum_staff === null ? 0 : max(0, $peakPlanned - $demand->maximum_staff), 'segments' => $segments] : $coverage;
    }

    /** Internal preview reservations are never persisted; mutation callers keep locking and an empty context. */
    public function assertCapacity(Shift $shift, int $userId, bool $lock = true, array $additionalAssignments = []): void
    {
        if (! $shift->order_demand_id || ! WorkforcePlanningSchema::demandsReady()) {
            return;
        }
        $query = OrderDemand::whereKey($shift->order_demand_id);
        $demand = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($demand->maximum_staff === null) {
            return; // Legacy/minimum demand has no implicit upper cap.
        }
        $shifts = $demand->shifts()->notCancelled()->with('assignments')->get();
        $virtual = collect($additionalAssignments)->filter(function (array $assignment) use ($shift, $userId, $demand, $shifts): bool {
            $other = $assignment['shift'];
            $person = (int) $assignment['user_id'];
            $actual = $shifts->firstWhere('id', $other->id);

            return (int) $other->order_demand_id === (int) $demand->id
                && ! ($other->id === $shift->id && $person === $userId)
                && ! ($actual && $actual->assignments->contains(fn ($item) => $item->user_id === $person && $item->status->blocksAvailability()));
        })->unique(fn ($assignment) => $assignment['shift']->id.':'.$assignment['user_id']);
        $points = collect([$shift->starts_at->timestamp])->merge($shifts->flatMap(fn ($s) => [$s->starts_at->timestamp, $s->ends_at->timestamp]))
            ->merge($virtual->flatMap(fn ($item) => [$item['shift']->starts_at->timestamp, $item['shift']->ends_at->timestamp]))
            ->filter(fn ($point) => $point >= $shift->starts_at->timestamp && $point < $shift->ends_at->timestamp)->unique();
        foreach ($points as $point) {
            $reserved = $shifts->filter(fn ($s) => $s->starts_at->timestamp <= $point && $s->ends_at->timestamp > $point)->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->blocksAvailability() && ! ($s->id === $shift->id && $a->user_id === $userId))->count());
            $reserved += $virtual->filter(fn ($item) => $item['shift']->starts_at->timestamp <= $point && $item['shift']->ends_at->timestamp > $point)->count();
            $this->check($reserved < $demand->maximum_staff, 'Höchstbedarf ist im Zeitfenster bereits reserviert.');
        }
    }

    public function assertPlannedCapacity(Shift $shift, array $context = [], bool $lock = false): void
    {
        if (! $shift->order_demand_id || ! WorkforcePlanningSchema::demandsReady()) {
            return;
        }
        $query = OrderDemand::whereKey($shift->order_demand_id);
        $demand = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($demand->maximum_staff === null) {
            return;
        }
        $exclude = array_unique(array_merge($context['exclude_shift_ids'] ?? [], $shift->id ? [$shift->id] : []));
        $others = $demand->shifts()->notCancelled()->whereNotIn('id', $exclude)->during($shift->starts_at, $shift->ends_at)->get()
            ->merge(collect($context['additional_shifts'] ?? [])->filter(fn ($other) => $other !== $shift && (int) $other->order_demand_id === (int) $shift->order_demand_id && $other->starts_at->lt($shift->ends_at) && $other->ends_at->gt($shift->starts_at)));
        $points = collect([$shift->starts_at->timestamp])->merge($others->flatMap(fn ($other) => [$other->starts_at->timestamp, $other->ends_at->timestamp]))->filter(fn ($point) => $point >= $shift->starts_at->timestamp && $point < $shift->ends_at->timestamp)->unique();
        foreach ($points as $point) {
            $planned = $others->filter(fn ($other) => $other->starts_at->timestamp <= $point && $other->ends_at->timestamp > $point)->sum('required_staff');
            $this->check($planned + $shift->required_staff <= $demand->maximum_staff, 'Geplante Einsatzplätze überschreiten den Höchstbedarf.');
        }
    }

    public function generate(int $id, int $revision, int $breakMinutes, User $actor): Shift
    {
        $this->access($actor);

        return OperationsTransaction::run(function () use ($id, $revision, $breakMinutes, $actor) {
            Order::lockForUpdate()->findOrFail(OrderDemand::findOrFail($id)->order_id);
            $demand = OrderDemand::lockForUpdate()->findOrFail($id);
            $this->check($demand->revision === $revision && $demand->status === 'active', 'Bedarf wurde geändert.');
            $open = $this->coverage($demand)['open'];
            $this->check($open > 0, 'Bedarf ist bereits vollständig geplant.');
            $profile = OperationsRuleProfile::where('is_active', true)->first();
            $minutes = $demand->starts_at->diffInMinutes($demand->ends_at);
            $this->check($profile && $minutes <= $profile->maximum_shift_minutes && $breakMinutes >= 0 && $breakMinutes < $minutes && ($minutes <= $profile->break_after_minutes || $breakMinutes >= $profile->minimum_break_minutes), 'Zeitraum oder Pause verletzt das Regelprofil. Bedarf gegebenenfalls in kürzere Zeitfenster aufteilen.');
            $this->check($demand->starts_at->isFuture(), 'Dienstbeginn liegt in der Vergangenheit.');
            $candidate = new Shift;
            $candidate->forceFill(['order_demand_id' => $demand->id, 'starts_at' => $demand->starts_at, 'ends_at' => $demand->ends_at, 'required_staff' => $open]);
            $this->assertPlannedCapacity($candidate, [], true);
            $shift = app(ShiftSchedulingService::class)->save($candidate, [
                'order_id' => $demand->order_id, 'title' => $demand->role_name.' · '.$demand->starts_at->format('d.m.'),
                'role_name' => $demand->role_name, 'starts_at' => CarbonImmutable::instance($demand->starts_at), 'ends_at' => CarbonImmutable::instance($demand->ends_at),
                'timezone' => $demand->timezone, 'required_staff' => $open, 'planned_break_minutes' => $breakMinutes, 'status' => 'draft',
            ], $actor);
            $shift->forceFill(['order_demand_id' => $demand->id])->save();
            if (WorkforcePlanningSchema::ready()) {
                $shift->qualifications()->sync($demand->qualification_ids ?? []);
            }
            app(OperationsAuditService::class)->record($demand, $actor, 'demand.planned', ['shift_id' => $shift->id]);

            return $shift;
        }, 3);
    }

    public function cancel(int $id, int $revision, User $actor): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($id, $revision, $actor) {
            $demand = OrderDemand::lockForUpdate()->findOrFail($id);
            $this->check($demand->revision === $revision && ! $demand->shifts()->notCancelled()->exists(), 'Verknüpfte Dienste zuerst stornieren oder Bedarf neu laden.');
            $demand->update(['status' => 'cancelled', 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($demand, $actor, 'demand.cancelled');
        }, 3);
    }

    private function access(User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        PlanningSchema::requireReady();
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
