<?php

namespace App\Support\Operations;

use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

final class PlanningLocks
{
    /** Caller owns a transaction. Resolve parents before locking children. */
    public static function acquire(array $shiftIds, array $userIds = [], array $orderIds = [], bool $includeTrashed = false): Collection
    {
        $orders = Shift::query()->when($includeTrashed, fn ($query) => $query->withTrashed())->whereKey($shiftIds)->pluck('order_id')->merge($orderIds)->unique()->sort()->values();
        Order::withTrashed()->whereKey($orders)->orderBy('id')->lockForUpdate()->get();
        $shifts = Shift::query()->when($includeTrashed, fn ($query) => $query->withTrashed())->whereKey($shiftIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($shifts->count() === count(array_unique($shiftIds)), 404);
        // A concurrent order change must not make us lock a second parent out of order.
        abort_unless($shifts->every(fn ($shift) => $orders->contains($shift->order_id)), 409);
        $assigned = ShiftAssignment::blocking()->whereIn('shift_id', $shifts->keys())->pluck('user_id')->all();
        User::whereKey(array_unique(array_merge($userIds, $assigned)))->orderBy('id')->lockForUpdate()->get();

        return $shifts;
    }
}
