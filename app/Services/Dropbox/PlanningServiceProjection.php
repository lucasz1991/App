<?php

namespace App\Services\Dropbox;

use App\Models\Customer;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Validation\ValidationException;

/** Explicit service identity is separate from train, place and employee identity. */
class PlanningServiceProjection
{
    public const FIELDS = ['service_reference', 'service_title', 'service_type', 'service_location'];

    public function current(DropboxRecord $record, Order $order): array
    {
        return [
            'service_reference' => $record->metadata['service_reference'] ?? $order->order_number ?? $order->public_id,
            'service_title' => $order->title,
            'service_type' => $order->service_type ?? '',
            'service_location' => $order->location_name ?? '',
        ];
    }

    public function order(DropboxConnection $connection, DropboxRecord $record, Shift $shift, Customer $customer, array $values): Order
    {
        $reference = trim((string) ($values['service_reference'] ?? ''));
        if ($shift->exists) {
            $order = Order::lockForUpdate()->findOrFail($shift->order_id);
            if ($reference !== '' && ! in_array($reference, [$this->current($record, $order)['service_reference'], $order->order_number, $order->public_id], true)) {
                throw ValidationException::withMessages(['sync' => 'Die Leistungs-ID einer bestehenden Schicht wurde geändert. Eine Umbuchung auf eine andere Leistung muss geprüft werden.']);
            }

            return $order;
        }
        if ($reference === '') {
            return new Order;
        }
        $mappedShiftIds = DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'Shift')
            ->where('metadata->service_reference', $reference)->pluck('model_id');
        $mappedOrderIds = Shift::whereIn('id', $mappedShiftIds)->pluck('order_id');
        $orders = Order::where(fn ($query) => $query->where('public_id', $reference)->orWhere('order_number', $reference)->orWhereIn('id', $mappedOrderIds))->lockForUpdate()->get();
        if ($orders->count() > 1 || ($orders->first() && (int) $orders->first()->customer_id !== (int) $customer->id)) {
            throw ValidationException::withMessages(['sync' => 'Leistungs-ID ist mehrdeutig oder gehört zu einem anderen Kunden.']);
        }
        $order = $orders->first() ?? new Order;
        if ($order->exists) {
            foreach (['service_title' => 'title', 'service_type' => 'service_type', 'service_location' => 'location_name'] as $field => $attribute) {
                if (isset($values[$field]) && trim((string) $values[$field]) !== '' && (string) $order->{$attribute} !== (string) $values[$field]) {
                    throw ValidationException::withMessages(['sync' => 'Neue Schicht enthält abweichende Leistungsdaten zur bereits bekannten Leistungs-ID. Bitte die Leistungsdaten abgleichen.']);
                }
            }
        }
        $record->metadata = [...($record->metadata ?? []), 'service_reference' => $reference];

        return $order;
    }

    public function attributes(Order $order, array $values, bool $newShift = false): array
    {
        if ($order->exists) {
            foreach (['service_title', 'service_type', 'service_location'] as $field) {
                if ($newShift && isset($values[$field]) && trim((string) $values[$field]) === '') {
                    unset($values[$field]); // A new copy has no baseline authorizing a deletion.
                } elseif (! $newShift && $field === 'service_title'
                    && array_key_exists($field, $values) && trim((string) $values[$field]) === '') {
                    throw ValidationException::withMessages(['sync' => 'Der Leistungstitel darf nicht leer sein.']);
                }
            }
        }

        return [
            'title' => trim((string) ($values['service_title'] ?? '')) ?: ($order->title ?: $values['location']),
            'service_type' => array_key_exists('service_type', $values) ? (trim((string) $values['service_type']) ?: null) : ($order->exists ? $order->service_type : ($values['role'] ?: 'Einsatz')),
            'location_name' => array_key_exists('service_location', $values) ? $values['service_location'] : ($order->location_name ?? (! $order->exists ? $values['location'] : null)),
        ];
    }

    /** Backfill an absent service place only when every existing shift agrees. */
    public function backfill(DropboxRecord $record, Order $order): void
    {
        if (($record->metadata['native_service_projection'] ?? 0) >= 1 || ! ($record->metadata['imported_order'] ?? false) || filled($order->location_name) || $order->customer_location_id || $order->location_snapshot) {
            return;
        }
        if (DropboxRecord::where('connection_id', $record->connection_id)->where('model_type', 'Shift')
            ->whereIn('model_id', $order->shifts()->select('id'))->where('metadata->native_service_projection', '>=', 1)->exists()) {
            return; // Another row already initialized this shared service; an empty place can now be intentional.
        }
        $places = $order->shifts()->pluck('location_name')->map(fn ($place) => trim((string) $place))->unique();
        if ($places->count() === 1 && $places->first() !== '') {
            $order->update(['location_name' => $places->first()]);
        }
    }
}
