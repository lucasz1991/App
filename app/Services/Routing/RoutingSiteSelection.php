<?php

namespace App\Services\Routing;

use App\Models\CustomerLocation;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Operations\SiteSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Server-created selection. No browser JSON can supply confirmed coordinates. */
final readonly class RoutingSiteSelection
{
    private function __construct(private string $kind, private int $id, private string $basis) {}

    public static function fromLocation(CustomerLocation $location): self
    {
        $current = CustomerLocation::findOrFail($location->id);
        app(SiteSnapshotService::class)->snapshotForLocation($current);

        return new self('location', (int) $current->id, self::locationBasis($current));
    }

    public static function fromSource(Shift $source): self
    {
        $current = Shift::findOrFail($source->id);

        return new self('source', (int) $current->id, self::sourceBasis($current));
    }

    public function apply(Order|Shift $record): void
    {
        if ($this->kind === 'location') {
            $location = CustomerLocation::when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->findOrFail($this->id);
            $customerId = $record instanceof Order ? $record->customer_id : Order::findOrFail($record->order_id)->customer_id;
            $this->check((int) $location->customer_id === (int) $customerId, 'Der Einsatzort gehört nicht zum Kunden dieses Auftrags.');
            $this->check(hash_equals($this->basis, self::locationBasis($location)), 'Der Einsatzort wurde geändert. Bitte erneut auswählen.');
            $record->customer_location_id = $location->id;
            $record->location_snapshot = app(SiteSnapshotService::class)->snapshotForLocation($location);
            $record->location_name = $location->name;

            return;
        }
        $source = Shift::when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->findOrFail($this->id);
        $this->check($record instanceof Shift && (int) $source->order_id === (int) $record->order_id, 'Standortsnapshots dürfen nur innerhalb desselben Auftrags kopiert werden.');
        $this->check(hash_equals($this->basis, self::sourceBasis($source)), 'Der Quelldienst wurde geändert. Bitte erneut prüfen.');
        $record->customer_location_id = $source->customer_location_id;
        $record->location_snapshot = $source->location_snapshot;
        $record->location_name = $source->location_name;
    }

    private static function locationBasis(CustomerLocation $location): string
    {
        return hash('sha256', json_encode([$location->id, $location->customer_id, $location->is_active, $location->revision,
            $location->name, $location->street, $location->postal_code, $location->city, $location->country,
            $location->latitude, $location->longitude, $location->coordinates_confirmed_at?->toISOString()], JSON_THROW_ON_ERROR));
    }

    private static function sourceBasis(Shift $shift): string
    {
        return hash('sha256', json_encode([$shift->id, $shift->order_id, $shift->revision, $shift->customer_location_id, $shift->location_name, $shift->location_snapshot], JSON_THROW_ON_ERROR));
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['customerLocationId' => $message]);
        }
    }
}
