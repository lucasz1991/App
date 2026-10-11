<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\OrderSchedulingService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Routing\RoutingSiteSelection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RoutingSiteSelectionSecurityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Order $order;

    private CustomerLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_11_120000_add_confirmed_routing_locations.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-01T00:00:00Z'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Standortprüfung', 'is_active' => true]);
        $this->location = CustomerLocation::create(['customer_id' => $customer->id, 'name' => 'Bestätigter Einsatz', 'country' => 'DE', 'is_active' => true, 'revision' => 1, 'latitude' => 52.5, 'longitude' => 13.4, 'coordinates_confirmed_at' => now(), 'updated_by' => $this->actor->id]);
        $this->order = Order::create(['customer_id' => $customer->id, 'customer_location_id' => $this->location->id, 'title' => 'Standortprüfung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-01T00:00:00+02:00', 'ends_at' => '2027-06-01T00:00:00+02:00', 'required_staff' => 2]);
        OperationsRuleProfile::create(['name' => 'QA', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'approved_at' => now(), 'created_by' => $this->actor->id]);
        Http::preventStrayRequests();
    }

    public function test_new_shift_cannot_inject_a_confirmed_raw_snapshot(): void
    {
        $shift = app(ShiftSchedulingService::class)->save(new Shift, $this->attributes() + ['location_name' => 'Manipuliert', 'location_snapshot' => $this->fakeSnapshot()], $this->actor);
        $this->assertNull($shift->location_snapshot);
        $this->assertNull($shift->customer_location_id);
    }

    public function test_existing_shift_ignores_tampered_raw_coordinates_and_keeps_saved_facts(): void
    {
        $shift = $this->shift();
        $shift = app(ShiftSchedulingService::class)->save($shift, ['notes' => 'Neue Notiz', 'location_snapshot' => $this->fakeSnapshot(), 'expected_revision' => $shift->revision], $this->actor);
        $this->assertSame(52.5, $shift->location_snapshot['latitude']);
        $this->assertSame('Bestätigter Einsatz', $shift->location_snapshot['name']);
    }

    public function test_explicit_location_id_derives_canonical_coordinates_on_server(): void
    {
        $shift = app(ShiftSchedulingService::class)->save(new Shift, $this->attributes() + ['location_name' => 'Manipuliert', 'customer_location_id' => $this->location->id, 'location_snapshot' => $this->fakeSnapshot()], $this->actor);
        $this->assertSame(52.5, $shift->location_snapshot['latitude']);
        $this->assertSame('Bestätigter Einsatz', $shift->location_name);
    }

    public function test_order_service_also_ignores_raw_snapshot_and_uses_canonical_selected_site(): void
    {
        $order = app(OrderSchedulingService::class)->save($this->order, ['customer_location_id' => $this->location->id, 'location_snapshot' => $this->fakeSnapshot()], $this->actor);
        $this->assertSame(52.5, $order->location_snapshot['latitude']);
    }

    public function test_a_json_object_cannot_impersonate_the_server_selection(): void
    {
        $this->expectException(ValidationException::class);
        app(ShiftSchedulingService::class)->save(new Shift, $this->attributes(), $this->actor, ['routing_site_selection' => ['kind' => 'source', 'snapshot' => $this->fakeSnapshot()]]);
    }

    public function test_typed_location_revalidates_changes_between_selection_and_locked_save(): void
    {
        $selection = RoutingSiteSelection::fromLocation($this->location);
        $this->location->update(['latitude' => 53, 'revision' => 2]);
        $this->expectException(ValidationException::class);
        app(ShiftSchedulingService::class)->save(new Shift, $this->attributes(), $this->actor, ['routing_site_selection' => $selection]);
    }

    public function test_trusted_source_copy_preserves_saved_site_after_master_changes(): void
    {
        $source = $this->shift();
        $selection = RoutingSiteSelection::fromSource($source);
        $this->location->update(['latitude' => 53, 'revision' => 2]);
        $copy = app(ShiftSchedulingService::class)->save(new Shift, $this->attributes(), $this->actor, ['routing_site_selection' => $selection]);
        $this->assertSame(52.5, $copy->location_snapshot['latitude']);
        $this->assertSame(1, $copy->location_snapshot['revision']);
        $this->assertSame($source->customer_location_id, $copy->customer_location_id);
    }

    public function test_typed_source_cannot_cross_an_order_boundary(): void
    {
        $source = $this->shift();
        $otherOrder = $this->order->replicate();
        $otherOrder->public_id = null;
        $otherOrder->order_number = null;
        $otherOrder->save();
        $this->expectException(ValidationException::class);
        app(ShiftSchedulingService::class)->save(new Shift, array_replace($this->attributes(), ['order_id' => $otherOrder->id]), $this->actor, ['routing_site_selection' => RoutingSiteSelection::fromSource($source)]);
    }

    public function test_typed_source_rejects_a_revised_source_shift(): void
    {
        $source = $this->shift();
        $selection = RoutingSiteSelection::fromSource($source);
        $source->forceFill(['revision' => 2])->save();
        $this->expectException(ValidationException::class);
        app(ShiftSchedulingService::class)->save(new Shift, $this->attributes(), $this->actor, ['routing_site_selection' => $selection]);
    }

    private function attributes(): array
    {
        return ['order_id' => $this->order->id, 'title' => 'QA Einsatz', 'role_name' => 'Tf', 'starts_at' => CarbonImmutable::parse('2027-05-12T10:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-12T11:00:00+02:00'), 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 0, 'status' => 'draft'];
    }

    private function shift(): Shift
    {
        return Shift::create($this->attributes() + ['created_by' => $this->actor->id])->fresh();
    }

    private function fakeSnapshot(): array
    {
        return ['confirmed' => true, 'name' => 'Manipuliert', 'latitude' => 1.23, 'longitude' => 4.56, 'revision' => 999];
    }
}
