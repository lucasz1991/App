<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderLifecycleService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['planned', 'in_progress', 'cancelled'],
        'planned' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => ['invoiced'],
        'invoiced' => [],
        'cancelled' => [],
    ];

    public function transition(
        Order $order,
        OrderStatus|string $toStatus,
        User $actor,
        ?string $note = null,
    ): Order {
        $targetStatus = $this->normalizeStatus($toStatus);

        return DB::transaction(function () use ($order, $targetStatus, $actor, $note): Order {
            $lockedOrder = Order::query()
                ->with('customer')
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $currentStatus = $lockedOrder->status;

            if (! in_array($targetStatus, $this->allowedTransitions($currentStatus), true)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Der Auftragsstatus kann nicht von „%s“ auf „%s“ geändert werden.',
                        $currentStatus->label(),
                        $targetStatus->label(),
                    ),
                ]);
            }

            $lockedOrder->forceFill([
                'status' => $targetStatus,
                'updated_by' => $actor->getKey(),
            ])->save();

            $lockedOrder->statusHistory()->create([
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'changed_by' => $actor->getKey(),
                'note' => $note,
            ]);

            return $lockedOrder->load([
                'customer',
                'statusHistory.changedBy',
                'shifts.assignments.user',
            ]);
        });
    }

    /** @return list<OrderStatus> */
    public function allowedTransitions(OrderStatus|string $status): array
    {
        $status = $this->normalizeStatus($status);

        return array_map(
            static fn (string $value): OrderStatus => OrderStatus::from($value),
            self::TRANSITIONS[$status->value],
        );
    }

    private function normalizeStatus(OrderStatus|string $status): OrderStatus
    {
        if ($status instanceof OrderStatus) {
            return $status;
        }

        $normalized = OrderStatus::tryFrom($status);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'status' => 'Der angegebene Auftragsstatus ist ungültig.',
            ]);
        }

        return $normalized;
    }
}
