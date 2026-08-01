<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSchedulingService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function save(Order $order, array $attributes, User $actor): Order
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Order {
            $persistedOrder = $order->exists
                ? Order::query()->lockForUpdate()->findOrFail($order->getKey())
                : $order;

            $startsAt = $attributes['starts_at'] ?? $persistedOrder->starts_at;
            $endsAt = $attributes['ends_at'] ?? $persistedOrder->ends_at;

            if (! $startsAt instanceof CarbonInterface || ! $endsAt instanceof CarbonInterface || $endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages([
                    'endsAt' => 'Das Auftragsende muss nach dem Auftragsbeginn liegen.',
                ]);
            }

            $customerId = (int) ($attributes['customer_id'] ?? $persistedOrder->customer_id);
            $customer = Customer::withTrashed()->lockForUpdate()->find($customerId);
            $keepsExistingCustomer = $persistedOrder->exists && (int) $persistedOrder->customer_id === $customerId;

            if ($customer === null || $customer->trashed() || (! $customer->is_active && ! $keepsExistingCustomer)) {
                throw ValidationException::withMessages([
                    'customerId' => 'Für neue Aufträge und Kundenwechsel ist ein aktiver Kunde erforderlich.',
                ]);
            }

            if ($persistedOrder->exists) {
                $outsideShift = Shift::query()
                    ->notCancelled()
                    ->where('order_id', $persistedOrder->getKey())
                    ->where(function ($query) use ($startsAt, $endsAt): void {
                        $query->where('starts_at', '<', $startsAt)
                            ->orWhere('ends_at', '>', $endsAt);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($outsideShift !== null) {
                    throw ValidationException::withMessages([
                        'startsAt' => sprintf(
                            'Der Auftragszeitraum muss die bestehende Schicht „%s“ (%s bis %s) einschließen.',
                            $outsideShift->title,
                            $outsideShift->starts_at->format('d.m.Y H:i'),
                            $outsideShift->ends_at->format('d.m.Y H:i'),
                        ),
                    ]);
                }

                unset($attributes['status']);
            }

            $persistedOrder->fill($attributes);
            $persistedOrder->updated_by = $actor->getKey();

            if (! $persistedOrder->exists) {
                $persistedOrder->created_by = $actor->getKey();
            }

            $persistedOrder->save();

            return $persistedOrder->load(['customer', 'shifts.assignments']);
        }, 3);
    }
}
