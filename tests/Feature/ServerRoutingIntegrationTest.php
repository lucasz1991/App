<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Http\Controllers\RoutingTileController;
use App\Models\Customer;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\EmployeeLocation\EmployeeLocationReader;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ServerRoutingIntegrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private string $cacheDirectory;

    private User $actor;

    private User $employee;

    private Order $order;

    private int $routeSeconds = 1800;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_11_120100_create_routing_monthly_usage_table.php'))->up();
        Schema::table('shifts', function (Blueprint $table): void {
            $table->json('location_snapshot')->nullable();
            $table->json('disposition_details')->nullable();
        });
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00+02:00'));
        $this->cacheDirectory = storage_path('framework/testing/routing-integration-'.Str::uuid());
        app()->instance(ServerRoutingCache::class, new ServerRoutingCache($this->cacheDirectory));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($this->actor);
        OperationsRuleProfile::create(['name' => 'Cache-Test', 'minimum_rest_minutes' => 30, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()]);
        $customer = Customer::create(['company_name' => 'Cache QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Cache QA', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(3), 'required_staff' => 5, 'created_by' => $this->actor->id]);
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'global-routing-test-key');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory) && str_starts_with($this->cacheDirectory, storage_path('framework/testing/routing-integration-'))) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    private function request(): array
    {
        return ['origin' => ['latitude' => 52.52, 'longitude' => 13.4], 'destination' => ['latitude' => 53.55, 'longitude' => 10.0], 'departure' => CarbonImmutable::now()->addHour()];
    }

    private function fakeRoute(int $seconds = 1800): void
    {
        $this->routeSeconds = $seconds;
        Http::fake(['api.tomtom.com/routing/*' => fn () => Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => $this->routeSeconds, 'lengthInMeters' => 20000], 'legs' => [['points' => [['latitude' => 52.52, 'longitude' => 13.4], ['latitude' => 53.55, 'longitude' => 10.0]]]]]]], 200, ['Cache-Control' => 'no-cache, no-transform'])]);
    }

    private function fakeTile(array $headers = []): void
    {
        Http::fake(['api.tomtom.com/map/*' => Http::response('global-tile-fixture', 200, array_replace(['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300'], $headers))]);
    }

    private function shift(string $start = '09:00', string $end = '10:00', float $latitude = 52.52): Shift
    {
        return Shift::create(['order_id' => $this->order->id, 'title' => 'Cache-Dienst', 'role_name' => 'Tf', 'starts_at' => '2027-05-12T'.$start.':00+02:00', 'ends_at' => '2027-05-12T'.$end.':00+02:00', 'timezone' => 'Europe/Berlin', 'status' => 'draft', 'required_staff' => 5, 'planned_break_minutes' => 0, 'location_name' => 'Bestätigter Ort', 'location_snapshot' => ['name' => 'Bestätigter Ort', 'address' => 'QA', 'latitude' => $latitude, 'longitude' => 13.4, 'revision' => 1, 'confirmed' => true], 'created_by' => $this->actor->id])->fresh();
    }

    private function reserve(Shift $shift): void
    {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => ShiftAssignmentStatus::Confirmed, 'assigned_by' => $this->actor->id, 'plan_revision' => $shift->revision]);
    }

    public function test_tiles_are_shared_between_authorized_users_without_browser_cache_headers(): void
    {
        $this->fakeTile();
        $controller = app(RoutingTileController::class);
        foreach ([$this->actor, User::factory()->create(['role' => 'admin', 'status' => true])] as $user) {
            $this->actingAs($user);
            $response = $controller(Request::create('/operations/routing/tiles/1/0/0'), 1, 0, 0);
            $this->assertSame('global-tile-fixture', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Scope'));
            $this->assertFalse($response->headers->has('X-RailTime-Tile-Cache-Seconds'));
        }
        Http::assertSentCount(1);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_planned_summary_reuse_is_global_and_reports_its_age(): void
    {
        $this->fakeRoute();
        $first = app(TomTomRoutingService::class)->summaryManyForDisplay(['first' => $this->request()])['first'];
        $second = (new TomTomRoutingService)->summaryManyForDisplay(['other-employee' => $this->request()])['other-employee'];
        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['cache_hit']);
        $this->assertSame($first['duration_seconds'], $second['duration_seconds']);
        $this->assertSame(now()->utc()->toIso8601String(), $second['calculated_at']);
        $this->assertSame(now()->utc()->addMinutes(5)->toIso8601String(), $second['expires_at']);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        Http::assertSentCount(1);
    }

    public function test_summary_identity_uses_exact_departure_coordinates_and_direction(): void
    {
        $this->fakeRoute();
        $request = $this->request();
        $equivalent = array_replace($request, ['departure' => $request['departure']->utc()->toIso8601String()]);
        $later = array_replace($request, ['departure' => $request['departure']->addSecond()]);
        $other = array_replace($request, ['origin' => ['latitude' => 52.5200001, 'longitude' => 13.4]]);
        $reverse = ['origin' => $request['destination'], 'destination' => $request['origin'], 'departure' => $request['departure']];
        $routing = app(TomTomRoutingService::class);
        $routing->summaryManyForDisplay(['a' => $request]);
        $results = $routing->summaryManyForDisplay(['same' => $equivalent, 'later' => $later, 'other' => $other, 'reverse' => $reverse]);
        $this->assertTrue($results['same']['cache_hit']);
        foreach (['later', 'other', 'reverse'] as $id) {
            $this->assertFalse($results[$id]['cache_hit']);
        }
        Http::assertSentCount(4);
    }

    public function test_geometry_and_summary_have_separate_cache_entries_and_ttls(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        $summary = $routing->summaryManyForDisplay(['a' => $request])['a'];
        $first = $routing->geometryForDisplay(...array_values($request));
        $second = $routing->geometryForDisplay(...array_values($request));
        $this->assertArrayNotHasKey('points', $summary);
        $this->assertCount(2, $first['points']);
        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['cache_hit']);
        $this->assertSame(now()->utc()->addMinutes(15)->toIso8601String(), $second['expires_at']);
        Http::assertSentCount(2);
    }

    public function test_fresh_binding_conflict_is_not_masked_by_a_warm_nonconflicting_display(): void
    {
        $this->fakeRoute(60);
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $service = app(TravelAssessmentService::class);
        $display = $service->assessMany($target, collect([$this->employee]), [], false, true)[$this->employee->id];
        $this->assertFalse($display['travel']['before']['cache_hit']);
        $this->assertSame([], $display['issues']);
        $this->fakeRoute(1800);
        $preflight = $service->preflight($target, collect([$this->employee]), ['display' => true, 'cache' => true, 'cache_purpose' => 'display']);
        $this->assertSame(1800, $preflight->assessments[$this->employee->id]['travel']['before']['duration_seconds']);
        $this->assertSame('travel_before', $preflight->assessments[$this->employee->id]['issues'][0]['code']);
        $this->assertArrayNotHasKey('cache_hit', $preflight->assessments[$this->employee->id]['travel']['before']);
        $stillCached = $service->assessMany($target, collect([$this->employee]), [], false, true)[$this->employee->id];
        $this->assertTrue($stillCached['travel']['before']['cache_hit']);
        $this->assertSame(60, $stillCached['travel']['before']['duration_seconds']);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_default_summary_and_geometry_always_bypass_all_cache_reads_and_writes(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        foreach ([1, 2] as $attempt) {
            $this->assertArrayNotHasKey('cache_hit', $routing->summaryMany(['a' => $request])['a']);
            $this->assertArrayNotHasKey('cache_hit', $routing->geometry(...array_values($request)));
        }
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(4);
    }

    public function test_saved_connection_tests_are_fresh_even_for_an_identical_warm_route(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $routing->summaryManyForDisplay(['warm' => ['origin' => ['latitude' => 52.520008, 'longitude' => 13.404954], 'destination' => ['latitude' => 52.516275, 'longitude' => 13.377704], 'departure' => now()]]);
        foreach ([1, 2] as $attempt) {
            $this->assertArrayNotHasKey('cache_hit', $routing->testConnection());
        }
        $this->assertSame(1, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(3);
    }

    public function test_gps_arrival_summary_and_geometry_never_create_shared_entries(): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['use_employee_locations' => true]));
        $this->fakeRoute(300);
        $target = $this->shift('06:30', '07:30');
        $point = ['latitude' => 53.0, 'longitude' => 13.0, 'accuracy_m' => 12, 'measured_at' => now()->subMinutes(5)->toIso8601String()];
        $reader = \Mockery::mock(EmployeeLocationReader::class);
        $reader->shouldReceive('latestForMany')->times(8)->andReturn([$this->employee->id => ['status' => 'available', 'reason' => null, 'point' => $point]]);
        app()->instance(EmployeeLocationReader::class, $reader);
        $service = app(TravelAssessmentService::class);
        foreach ([1, 2] as $attempt) {
            $detail = $service->assessMany($target, collect([$this->employee]), ['include_employee_location' => true, 'actor' => $this->actor], false, true)[$this->employee->id];
            $this->assertSame('known', $detail['arrival']['status']);
            $this->assertArrayNotHasKey('cache_hit', $detail['arrival']);
            $this->assertArrayNotHasKey('cache_hit', $service->geometryFor($target, $this->employee, 'arrival', ['actor' => $this->actor]));
        }
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(4);
    }

    public function test_expired_routes_are_recalculated_and_shorter_settings_are_immediate(): void
    {
        $this->fakeRoute();
        $request = $this->request();
        $routing = app(TomTomRoutingService::class);
        $routing->summaryManyForDisplay(['a' => $request]);
        $this->travelTo(now()->addMinutes(2));
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['server_route_cache_minutes' => 1]));
        $this->assertFalse($routing->summaryManyForDisplay(['a' => $request])['a']['cache_hit']);
        $this->travelTo(now()->addMinute());
        $this->assertFalse($routing->summaryManyForDisplay(['a' => $request])['a']['cache_hit']);
        Http::assertSentCount(3);
    }

    public function test_shorter_provider_tile_lifetime_and_age_bound_reuse(): void
    {
        $this->fakeTile(['Cache-Control' => 'public, max-age=30', 'Age' => '10']);
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        $routing->tile(1, 0, 0);
        Http::assertSentCount(1);
        $this->travelTo(now()->addSeconds(21));
        $routing->tile(1, 0, 0);
        Http::assertSentCount(2);
    }

    public static function restrictiveTileHeaders(): array
    {
        return [
            'private' => [['Cache-Control' => 'private, max-age=300']],
            'no-cache' => [['Cache-Control' => 'public, max-age=300, no-cache']],
            'no-store' => [['Cache-Control' => 'public, max-age=300, no-store']],
            'missing positive lifetime' => [['Cache-Control' => 'public']],
            'expired' => [['Cache-Control' => 'public, max-age=300', 'Age' => '301']],
            'malformed shared lifetime' => [['Cache-Control' => 'public, max-age=300, s-maxage=invalid']],
            'duplicate shared lifetime' => [['Cache-Control' => 'public, max-age=300, s-maxage=60, s-maxage=90']],
        ];
    }

    #[DataProvider('restrictiveTileHeaders')]
    public function test_restrictive_tiles_are_never_reused(array $headers): void
    {
        $this->fakeTile($headers);
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        $routing->tile(1, 0, 0);
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(2);
    }

    public function test_warm_hits_do_not_reserve_budget_and_unknown_misses_are_not_cached(): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['routing_monthly_limit' => 1]));
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        $routing->summaryManyForDisplay(['a' => $request]);
        $this->assertTrue($routing->summaryManyForDisplay(['a' => $request])['a']['cache_hit']);
        $this->assertSame('budget_exhausted', $routing->summaryMany(['a' => $request])['a']['reason']);
        $other = array_replace($request, ['departure' => $request['departure']->addSecond()]);
        $this->assertSame('budget_exhausted', $routing->summaryManyForDisplay(['b' => $other])['b']['reason']);
        $this->assertSame(1, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(1);
    }

    public function test_provider_failures_do_not_enter_or_mask_the_cache(): void
    {
        Http::fake(['api.tomtom.com/*' => Http::response([], 503)]);
        $routing = app(TomTomRoutingService::class);
        foreach ([1, 2] as $attempt) {
            $this->assertSame('unknown', $routing->summaryManyForDisplay(['a' => $this->request()])['a']['status']);
        }
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertSentCount(2);
    }

    public function test_disabled_cache_bypasses_read_and_write_without_affecting_routing(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        $routing->summaryManyForDisplay(['a' => $request]);
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['server_cache_enabled' => false]));
        $result = $routing->summaryManyForDisplay(['a' => $request])['a'];
        $this->assertFalse($result['cache_hit']);
        $this->assertNull($result['expires_at']);
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['server_cache_enabled' => true]));
        $this->assertTrue($routing->summaryManyForDisplay(['a' => $request])['a']['cache_hit']);
        Http::assertSentCount(2);
    }

    public function test_api_key_rotation_changes_all_global_cache_identities(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $routing->summaryManyForDisplay(['a' => $this->request()]);
        TomTomSettings::save(TomTomSettings::all(), 'rotated-global-test-key');
        $this->assertFalse($routing->summaryManyForDisplay(['a' => $this->request()])['a']['cache_hit']);
        Http::assertSentCount(2);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_key_rotation_while_provider_is_running_discards_and_never_persists_old_result(): void
    {
        Http::fake(function () {
            TomTomSettings::save(TomTomSettings::all(), 'rotated-mid-request-test-key');

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 300, 'lengthInMeters' => 1000]]]]);
        });
        $result = app(TomTomRoutingService::class)->summaryManyForDisplay(['a' => $this->request()])['a'];
        $this->assertSame('settings_changed', $result['reason']);
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
    }

    public function test_transaction_guard_applies_even_when_route_and_tiles_are_cached(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        $routing->summaryManyForDisplay(['a' => $request]);
        $this->fakeTile();
        $routing->tile(1, 0, 0);
        DB::transaction(function () use ($routing, $request): void {
            $this->assertSame('transaction_requires_preflight', $routing->summaryManyForDisplay(['a' => $request])['a']['reason']);
            $this->assertSame('transaction_requires_preflight', $routing->tile(1, 0, 0)['reason']);
        });
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['map_tiles']);
    }

    public function test_integration_disable_key_deletion_and_missing_schema_apply_before_warm_hits(): void
    {
        $this->fakeRoute();
        $routing = app(TomTomRoutingService::class);
        $request = $this->request();
        $routing->summaryManyForDisplay(['a' => $request]);
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['enabled' => false]));
        $this->assertSame('disabled', $routing->summaryManyForDisplay(['a' => $request])['a']['reason']);
        TomTomSettings::save(array_replace(TomTomSettings::all(), ['enabled' => true]));
        Schema::drop('routing_monthly_usage');
        $this->assertSame('schema_missing', $routing->summaryManyForDisplay(['a' => $request])['a']['reason']);
        TomTomSettings::save(TomTomSettings::all(), null, true);
        $this->assertSame('disabled', $routing->summaryManyForDisplay(['a' => $request])['a']['reason']);
        Http::assertSentCount(1);
    }

    public function test_cache_clear_during_route_fetch_does_not_allow_the_late_reply_to_repopulate_it(): void
    {
        Http::fake(function () {
            (new ServerRoutingCache($this->cacheDirectory))->clear();

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 300, 'lengthInMeters' => 1000]]]]);
        });
        $routing = app(TomTomRoutingService::class);
        foreach ([1, 2] as $attempt) {
            $result = $routing->summaryManyForDisplay(['a' => $this->request()])['a'];
            $this->assertSame('known', $result['status']);
            $this->assertFalse($result['cache_hit']);
            $this->assertNull($result['expires_at']);
            $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        }
        Http::assertSentCount(2);
    }

    public function test_cache_clear_during_tile_fetch_prevents_late_tile_storage(): void
    {
        Http::fake(function () {
            (new ServerRoutingCache($this->cacheDirectory))->clear();

            return Http::response('late-tile', 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300']);
        });
        $routing = app(TomTomRoutingService::class);
        foreach ([1, 2] as $attempt) {
            $this->assertSame('known', $routing->tile(1, 0, 0)['status']);
            $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        }
        Http::assertSentCount(2);
    }

    public function test_shared_tile_lifetime_uses_full_provider_apparent_age(): void
    {
        $this->fakeTile(['Cache-Control' => 'public, max-age=600, s-maxage=60', 'Date' => gmdate('D, d M Y H:i:s \\G\\M\\T', time() - 120)]);
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        $routing->tile(1, 0, 0);
        Http::assertSentCount(2);
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
    }

    public function test_shared_only_positive_tile_lifetime_is_usable(): void
    {
        $this->fakeTile(['Cache-Control' => 'public, s-maxage=60']);
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        $routing->tile(1, 0, 0);
        Http::assertSentCount(1);
        $this->assertSame(1, app(ServerRoutingCache::class)->stats()['entries']);
    }

    public function test_shared_tile_lifetime_deducts_actual_response_delay(): void
    {
        Http::fake(function () {
            usleep(600000);

            return Http::response('slow-tile', 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300, s-maxage=1']);
        });
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        $routing->tile(1, 0, 0);
        Http::assertSentCount(2);
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
    }

    public function test_warm_tiles_and_three_route_hits_use_bounded_uncached_configuration_reads(): void
    {
        $this->fakeTile();
        $routing = app(TomTomRoutingService::class);
        $routing->tile(1, 0, 0);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $routing->tile(1, 0, 0);
        $settingReads = fn () => collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"settings"') || in_array('settings', $query['bindings'], true))->count();
        $tileQueries = $settingReads();
        $this->assertLessThanOrEqual(5, $tileQueries);
        $this->fakeRoute();
        $requests = [
            'a' => $this->request(),
            'b' => array_replace($this->request(), ['departure' => CarbonImmutable::now()->addHours(2)]),
            'c' => array_replace($this->request(), ['departure' => CarbonImmutable::now()->addHours(3)]),
        ];
        $routing->summaryManyForDisplay($requests);
        DB::flushQueryLog();
        $results = $routing->summaryManyForDisplay($requests);
        $routeQueries = $settingReads();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(7, $routeQueries);
        $this->assertTrue(collect($results)->every(fn ($result) => $result['cache_hit'] === true));
    }
}
