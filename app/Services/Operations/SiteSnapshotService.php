<?php

namespace App\Services\Operations;

use App\Models\CustomerLocation;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Routing\RoutingSiteSelection;
use App\Support\Operations\SchemaReadiness;
use App\Support\Routing\TomTomSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Own confirmed facts; changing the customer directory never moves a saved duty. */
class SiteSnapshotService
{
    /** Scheduling attributes never accept a raw provider/client coordinate snapshot. */
    public function schedulingSelection(array &$attributes, array $context = []): ?RoutingSiteSelection
    {
        unset($attributes['location_snapshot']);
        $selection = $context['routing_site_selection'] ?? null;
        if ($selection !== null && ! $selection instanceof RoutingSiteSelection) {
            throw ValidationException::withMessages(['customerLocationId' => 'Standortauswahl muss serverseitig bestätigt werden.']);
        }
        if (! self::ready()) {
            unset($attributes['customer_location_id']);

            return null;
        }
        if ($selection instanceof RoutingSiteSelection) {
            return $selection;
        }
        if (! empty($attributes['customer_location_id'])) {
            return RoutingSiteSelection::fromLocation(CustomerLocation::findOrFail($attributes['customer_location_id']));
        }

        return null;
    }

    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, fn () => Schema::hasColumns('shifts', ['customer_location_id', 'location_snapshot'])
            && Schema::hasColumns('orders', ['customer_location_id', 'location_snapshot'])
            && Schema::hasColumns('customer_locations', ['latitude', 'longitude', 'coordinates_confirmed_at']));
    }

    public static function confirmed(?array $snapshot): bool
    {
        return ($snapshot['confirmed'] ?? false) === true && filled($snapshot['name'] ?? null)
            && is_numeric($snapshot['latitude'] ?? null) && is_numeric($snapshot['longitude'] ?? null)
            && abs((float) $snapshot['latitude']) <= 90 && abs((float) $snapshot['longitude']) <= 180;
    }

    public function snapshotForLocation(CustomerLocation $location): array
    {
        if (! $location->is_active || ! $location->coordinates_confirmed_at
            || ! is_numeric($location->latitude) || ! is_numeric($location->longitude)) {
            throw ValidationException::withMessages(['customerLocationId' => 'Bitte einen aktiven Einsatzort mit ausdrücklich bestätigten Koordinaten auswählen.']);
        }
        $snapshot = ['name' => $location->name, 'address' => trim(implode(', ', array_filter([$location->street, trim($location->postal_code.' '.$location->city), $location->country]))),
            'latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude,
            'revision' => (int) $location->revision, 'confirmed' => true];
        if (! self::confirmed($snapshot)) {
            throw ValidationException::withMessages(['customerLocationId' => 'Die bestätigten Koordinaten sind ungültig.']);
        }

        return $snapshot;
    }

    public function prepare(Model $record): void
    {
        if (! self::ready() || ! ($record instanceof Order || $record instanceof Shift)) {
            return;
        }
        $order = $record instanceof Shift ? Order::find($record->order_id) : null;
        $customerId = $record instanceof Order ? $record->customer_id : $order?->customer_id;
        // Keep an existing snapshot when only its source directory changes.
        if ($record->customer_location_id) {
            $location = CustomerLocation::where('customer_id', $customerId)->find($record->customer_location_id);
            if (! $location) {
                throw ValidationException::withMessages(['customerLocationId' => 'Der Einsatzort gehört nicht zum Kunden dieses Auftrags.']);
            }
            if ((! $record->exists && ! self::confirmed($record->location_snapshot)) || ($record->exists && $record->isDirty('customer_location_id')) || ! self::confirmed($record->location_snapshot)) {
                $record->location_snapshot = $this->snapshotForLocation($location);
                $record->location_name = $location->name;
            } elseif ($record->isDirty('location_name') && $record->location_name !== ($record->location_snapshot['name'] ?? null)) {
                $record->customer_location_id = null;
                $record->location_snapshot = null;
            }
        } elseif (! $record->exists && $order && self::confirmed($order->location_snapshot)
            && (blank($record->location_name) || $record->location_name === $order->location_name)) {
            $record->customer_location_id = $order->customer_location_id;
            $record->location_snapshot = $order->location_snapshot;
            $record->location_name = $order->location_name;
        } elseif ($record->exists && $record->isDirty('customer_location_id')) {
            $record->location_snapshot = null;
        } elseif ($record->isDirty('location_name') && self::confirmed($record->location_snapshot)
            && $record->location_name !== ($record->location_snapshot['name'] ?? null)) {
            $record->location_snapshot = null;
        }
    }

    public function assertConfirmed(Shift $shift): void
    {
        if (self::ready() && TomTomSettings::all()['enabled'] && ! self::confirmed($shift->location_snapshot)) {
            throw ValidationException::withMessages(['workflow' => 'Vor einer verbindlichen Zuteilung oder Veröffentlichung muss der Einsatzstandort mit Koordinaten bestätigt werden.']);
        }
    }
}
