<?php

namespace App\Services\Operations;

use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\PlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderDemandService
{
    public function save(int $orderId, ?int $id, ?int $revision, array $data, User $actor): OrderDemand
    {
        $this->access($actor);
        $data = Validator::make($data, ['role_name' => 'required|string|max:160', 'required_staff' => 'required|integer|min:1|max:999', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return DB::transaction(function () use ($orderId, $id, $revision, $data, $start, $end, $actor) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $this->check($order->status->value !== 'cancelled' && $start->gte($order->starts_at) && $end->lte($order->ends_at), 'Bedarf muss in einem aktiven Auftragszeitraum liegen.');
            $demand = $id ? OrderDemand::where('order_id', $orderId)->lockForUpdate()->findOrFail($id) : new OrderDemand;
            $this->check(! $id || ($demand->revision === $revision && $demand->status === 'active'), 'Bedarf wurde geändert. Bitte neu laden.');
            if ($id && $demand->shifts()->notCancelled()->exists()) {
                $this->check($start->equalTo($demand->starts_at) && $end->equalTo($demand->ends_at) && $data['role_name'] === $demand->role_name && $data['timezone'] === $demand->timezone, 'Für geplante Bedarfe sind nur Personalzahlen änderbar. Zeit/Funktion über einen neuen Bedarf planen.');
            }
            $demand->fill(array_merge($data, ['order_id' => $orderId, 'starts_at' => $start, 'ends_at' => $end, 'revision' => $id ? $revision + 1 : 1, 'status' => 'active', 'created_by' => $id ? $demand->created_by : $actor->id]))->save();
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
        $planned = $confirmed = PHP_INT_MAX;
        foreach ($points->slice(0, -1) as $point) {
            $active = $shifts->filter(fn ($s) => $s->order_id === $demand->order_id && $s->role_name === $demand->role_name && $s->starts_at->timestamp <= $point && $s->ends_at->timestamp > $point);
            $planned = min($planned, $active->sum('required_staff'));
            $confirmed = min($confirmed, $active->sum(fn ($s) => $s->assignments->filter(fn ($a) => $a->status->value === 'confirmed' && $a->plan_revision === $s->published_revision && $s->published_revision === $s->revision)->count()));
        }

        return ['planned' => $planned === PHP_INT_MAX ? 0 : $planned, 'confirmed' => $confirmed === PHP_INT_MAX ? 0 : $confirmed,
            'open' => max(0, $demand->required_staff - ($planned === PHP_INT_MAX ? 0 : $planned))];
    }

    public function generate(int $id, int $revision, int $breakMinutes, User $actor): Shift
    {
        $this->access($actor);

        return DB::transaction(function () use ($id, $revision, $breakMinutes, $actor) {
            Order::lockForUpdate()->findOrFail(OrderDemand::findOrFail($id)->order_id);
            $demand = OrderDemand::lockForUpdate()->findOrFail($id);
            $this->check($demand->revision === $revision && $demand->status === 'active', 'Bedarf wurde geändert.');
            $open = $this->coverage($demand)['open'];
            $this->check($open > 0, 'Bedarf ist bereits vollständig geplant.');
            $profile = OperationsRuleProfile::where('is_active', true)->first();
            $minutes = $demand->starts_at->diffInMinutes($demand->ends_at);
            $this->check($profile && $minutes <= $profile->maximum_shift_minutes && $breakMinutes >= 0 && $breakMinutes < $minutes && ($minutes <= $profile->break_after_minutes || $breakMinutes >= $profile->minimum_break_minutes), 'Zeitraum oder Pause verletzt das Regelprofil. Bedarf gegebenenfalls in kürzere Zeitfenster aufteilen.');
            $this->check($demand->starts_at->isFuture(), 'Dienstbeginn liegt in der Vergangenheit.');
            $shift = app(ShiftSchedulingService::class)->save(new Shift, [
                'order_id' => $demand->order_id, 'title' => $demand->role_name.' · '.$demand->starts_at->format('d.m.'),
                'role_name' => $demand->role_name, 'starts_at' => CarbonImmutable::instance($demand->starts_at), 'ends_at' => CarbonImmutable::instance($demand->ends_at),
                'timezone' => $demand->timezone, 'required_staff' => $open, 'planned_break_minutes' => $breakMinutes, 'status' => 'draft',
            ], $actor);
            $shift->forceFill(['order_demand_id' => $demand->id])->save();
            app(OperationsAuditService::class)->record($demand, $actor, 'demand.planned', ['shift_id' => $shift->id]);

            return $shift;
        }, 3);
    }

    public function cancel(int $id, int $revision, User $actor): void
    {
        $this->access($actor);
        DB::transaction(function () use ($id, $revision, $actor) {
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
