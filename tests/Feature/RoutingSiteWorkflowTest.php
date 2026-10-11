<?php

namespace Tests\Feature;

use App\Livewire\Admin\EmployeeLocationSettings;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Admin\Settings as SystemSettings;
use App\Livewire\Admin\TomTomSettings;
use App\Livewire\Operations\RoutingSiteEditor;
use App\Livewire\Operations\ShiftTravelPanel;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\ShiftSeriesService;
use App\Services\Operations\SiteSnapshotService;
use App\Support\Routing\TomTomSettings as RoutingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RoutingSiteWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Order $order;

    private CustomerLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_11_120000_add_confirmed_routing_locations.php', '2026_10_11_120100_create_routing_monthly_usage_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Routing QA', 'is_active' => true]);
        $this->location = CustomerLocation::create(['customer_id' => $customer->id, 'name' => 'Bestätigter Einsatz', 'country' => 'DE', 'is_active' => true, 'revision' => 1, 'latitude' => 52.5, 'longitude' => 13.4, 'coordinates_confirmed_at' => now(), 'updated_by' => $this->admin->id]);
        $this->order = Order::create(['customer_id' => $customer->id, 'customer_location_id' => $this->location->id, 'title' => 'Standort QA', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'starts_at' => '2027-05-01T00:00+02:00', 'ends_at' => '2027-06-01T00:00+02:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 3]);
        OperationsRuleProfile::create(['name' => 'QA', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'approved_at' => now(), 'created_by' => $this->admin->id]);
    }

    private function shift(array $values = []): Shift
    {
        return Shift::create($values + ['order_id' => $this->order->id, 'title' => 'QA Einsatz', 'role_name' => 'Tf', 'starts_at' => '2027-05-12T10:00+02:00', 'ends_at' => '2027-05-12T11:00+02:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 3, 'planned_break_minutes' => 0, 'status' => 'draft', 'created_by' => $this->admin->id])->fresh();
    }

    public function test_confirmed_order_site_is_inherited_and_master_changes_do_not_move_saved_shifts(): void
    {
        $shift = $this->shift();
        $this->assertTrue(SiteSnapshotService::confirmed($shift->location_snapshot));
        $this->location->update(['latitude' => 53.0, 'name' => 'Neuer Stammpunkt', 'revision' => 2]);
        $shift->update(['notes' => 'Nur eine Notiz']);
        $this->assertSame(52.5, $shift->fresh()->location_snapshot['latitude']);
        $copy = $shift->replicate();
        $copy->public_id = null;
        $copy->save();
        $this->assertSame(52.5, $copy->location_snapshot['latitude']);
    }

    public function test_raw_location_change_clears_precision_instead_of_guessing(): void
    {
        $shift = $this->shift();
        $shift->update(['location_name' => 'Unbekannter Zugang']);
        $this->assertNull($shift->fresh()->location_snapshot);
        $this->assertNull($shift->fresh()->customer_location_id);
        $draft = $this->shift(['location_name' => 'Unbekannter Zugang']);
        $this->assertNull($draft->location_snapshot);
    }

    private function planningSchema(): void
    {
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
    }

    public function test_variant_copy_keeps_the_source_point_after_directory_coordinates_change(): void
    {
        $this->planningSchema();
        $source = $this->shift();
        $this->location->update(['latitude' => 53.0, 'revision' => 2]);
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$source->id], 'Standortkopie', 7, $this->admin);
        $preview = $service->preview($variant, $this->admin);
        $service->approve($variant->id, 1, $preview['fingerprint'], $this->admin);
        $ids = $service->apply($variant->id, 2, $this->admin);
        $copy = Shift::findOrFail($ids[0]);
        $this->assertSame(52.5, $copy->location_snapshot['latitude']);
        $this->assertSame(1, $copy->location_snapshot['revision']);
        $this->assertSame($source->customer_location_id, $copy->customer_location_id);
    }

    public function test_series_uses_the_confirmed_order_point_without_automatic_master_refresh(): void
    {
        $this->planningSchema();
        $this->location->update(['latitude' => 53.0, 'revision' => 2]);
        $service = app(ShiftSeriesService::class);
        $template = $service->saveTemplate(null, null, ['name' => 'Standortserie', 'title' => 'Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'start_time' => '10:00', 'end_time' => '11:00', 'end_next_day' => false, 'required_staff' => 1, 'planned_break_minutes' => 0, 'location_name' => $this->order->location_name, 'qualification_ids' => []], $this->admin);
        $request = ['template_id' => $template->id, 'order_id' => $this->order->id, 'from' => '2027-05-12', 'until' => '2027-05-13', 'weekdays' => [3, 4], 'exceptions' => []];
        $preview = $service->preview($request, $this->admin);
        $service->generate($request, $preview['fingerprint'], (string) Str::uuid(), $this->admin);
        $this->assertSame(2, Shift::count());
        foreach (Shift::all() as $shift) {
            $this->assertSame(52.5, $shift->location_snapshot['latitude']);
            $this->assertTrue(SiteSnapshotService::confirmed($shift->location_snapshot));
        }
    }

    public function test_foreign_customer_location_is_rejected(): void
    {
        $other = Customer::create(['company_name' => 'Fremder Kunde', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        Order::create(['customer_id' => $other->id, 'customer_location_id' => $this->location->id, 'title' => 'Fremder Bezug', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin']);
    }

    public function test_publication_requires_a_confirmed_site_when_feature_is_active(): void
    {
        RoutingSettings::save(RoutingSettings::DEFAULTS + [], 'test-routing-key');
        RoutingSettings::save(array_replace(RoutingSettings::all(), ['enabled' => true]));
        $shift = $this->shift(['location_name' => 'Ungeprüft']);
        $this->expectException(ValidationException::class);
        app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->admin);
    }

    public function test_site_editor_can_explicitly_refresh_a_saved_snapshot(): void
    {
        $shift = $this->shift();
        $this->location->update(['latitude' => 53.0, 'revision' => 2]);
        Livewire::actingAs($this->admin)->test(RoutingSiteEditor::class, ['subjectType' => 'shift', 'subjectId' => $shift->id])
            ->set('customerLocationId', (string) $this->location->id)->call('save')->assertHasNoErrors();
        $this->assertEquals(53.0, $shift->fresh()->location_snapshot['latitude']);
        $this->assertSame(2, $shift->fresh()->revision);
    }

    public function test_settings_load_and_save_never_contact_provider_and_mask_the_key(): void
    {
        Http::preventStrayRequests();
        Livewire::actingAs($this->admin)->test(TomTomSettings::class)->set('apiKey', 'secret-test-routing-key')->call('save')->assertSet('apiKey', '')->assertHasNoErrors();
        $html = Livewire::actingAs($this->admin)->test(TomTomSettings::class)->html();
        $this->assertStringNotContainsString('secret-test-routing-key', $html);
        Livewire::actingAs($this->admin)->test(EmployeeLocationSettings::class)->assertSee('Mitarbeiter-Standorte');
        Http::assertNothingSent();
    }

    public function test_settings_tabs_keep_the_declared_order_and_tomtom_url_selection(): void
    {
        (require database_path('migrations/2026_09_08_171000_create_employee_workplace_provisions.php'))->up();
        Http::preventStrayRequests();
        $settings = Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'tomtom'])->test(SystemSettings::class)->assertSet('initialTab', 'tomtom');
        $html = $settings->html();
        $dropbox = strpos($html, 'data-tab-panel-id="dropbox"');
        $tomtom = strpos($html, 'data-tab-panel-id="tomtom"');
        $locations = strpos($html, 'data-tab-panel-id="employee-locations"');
        $superadmin = strpos($html, 'data-tab-panel-id="superadmin"');
        $this->assertTrue($dropbox < $tomtom && $tomtom < $locations && $locations < $superadmin);
        Http::assertNothingSent();
    }

    public function test_invalid_new_api_key_is_cleared_from_the_response_snapshot(): void
    {
        Http::preventStrayRequests();
        $component = Livewire::actingAs($this->admin)->test(TomTomSettings::class)
            ->set('apiKey', 'invalid-secret !')->call('save')->assertHasErrors('key')->assertSet('apiKey', '');
        $this->assertStringNotContainsString('invalid-secret !', $component->html(false));
        Http::assertNothingSent();
    }

    public function test_five_minute_reserve_cannot_be_disabled_through_settings(): void
    {
        Livewire::actingAs($this->admin)->test(TomTomSettings::class)
            ->set('config.reserve_minutes', 0)->call('save')->assertHasErrors('reserve_minutes');
        $this->assertSame(5, RoutingSettings::all()['reserve_minutes']);
    }

    public function test_regular_admin_cannot_open_or_mutate_sensitive_settings(): void
    {
        $regular = User::factory()->create(['role' => 'admin', 'status' => true]);
        Livewire::actingAs($regular)->test(TomTomSettings::class)->assertForbidden();
        Livewire::actingAs($regular)->test(EmployeeLocationSettings::class)->assertForbidden();
    }

    public function test_travel_panel_is_lazy_and_only_selected_people_can_request_geometry(): void
    {
        Http::fake();
        $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
        $shift = $this->shift();
        $panel = Livewire::actingAs($this->admin)->test(ShiftTravelPanel::class, ['shiftId' => $shift->id, 'employeeIds' => [$staff->id]]);
        Http::assertNothingSent();
        $panel->call('load')->assertSet('loaded', true)->assertHasNoErrors();
        $panel->call('showRoute', $staff->id + 100, 'before')->assertForbidden();
    }

    public function test_selected_distribution_widget_mounts_lazy_travel_for_its_candidates(): void
    {
        $this->planningSchema();
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        User::factory()->create(['name' => 'Routing Verteilung QA', 'role' => 'staff', 'status' => true]);
        RoutingSettings::save(array_replace(RoutingSettings::DEFAULTS, ['enabled' => true]), 'test-routing-key');
        $shift = $this->shift();
        Http::preventStrayRequests();
        Livewire::actingAs($this->admin)->withQueryParams(['distribution' => '1'])->test(ShiftManagement::class)
            ->call('selectDistributionShift', $shift->id)->assertSeeHtml('aria-label="Fahrzeiten und Routen"');
        Http::assertNothingSent();
    }
}
