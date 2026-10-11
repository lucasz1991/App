<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Services\EmployeeLocation\EmployeeLocationReader;
use App\Services\Operations\AiAssistService;
use App\Services\Operations\PlanningEnhancementService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\ShiftAssignmentExceptionService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkTimeTerminalService;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TomTomRoutingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $employee;

    private Order $order;

    private string $cacheDirectory;

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
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Routing-Test', 'minimum_rest_minutes' => 30, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()]);
        $customer = Customer::create(['company_name' => 'Routing QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Routing QA', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(3), 'required_staff' => 5, 'created_by' => $this->actor->id]);
        Http::preventStrayRequests();
        $this->enable();
        $this->cacheDirectory = storage_path('framework/testing/tomtom-routing-'.Str::uuid());
        app()->instance(ServerRoutingCache::class, new ServerRoutingCache($this->cacheDirectory));
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory) && str_starts_with($this->cacheDirectory, storage_path('framework/testing/tomtom-routing-'))) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    private function enable(array $values = []): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true], $values), 'test-credential-012345');
    }

    private function request(): array
    {
        return ['origin' => ['latitude' => 52.52, 'longitude' => 13.4], 'destination' => ['latitude' => 53.55, 'longitude' => 10.0], 'departure' => now()->addHour()];
    }

    private function fakeRoute(int $seconds = 1800): void
    {
        $route = ['summary' => ['travelTimeInSeconds' => $seconds, 'lengthInMeters' => 20000], 'legs' => [['points' => [['latitude' => 52.52, 'longitude' => 13.4], ['latitude' => 53.55, 'longitude' => 10.0]]]]];
        Http::fake(['api.tomtom.com/*' => Http::response(['routes' => [$route]])]);
    }

    private function shift(string $start = '09:00', string $end = '10:00', float $latitude = 52.52): Shift
    {
        return Shift::create(['order_id' => $this->order->id, 'title' => 'Routing-Dienst', 'role_name' => 'Tf', 'starts_at' => '2027-05-12T'.$start.':00+02:00', 'ends_at' => '2027-05-12T'.$end.':00+02:00', 'timezone' => 'Europe/Berlin', 'status' => 'draft', 'required_staff' => 5, 'planned_break_minutes' => 0, 'location_name' => 'Bestätigter Ort', 'location_snapshot' => ['name' => 'Bestätigter Ort', 'address' => 'QA', 'latitude' => $latitude, 'longitude' => 13.4, 'revision' => 1, 'confirmed' => true], 'created_by' => $this->actor->id])->fresh();
    }

    private function reserve(Shift $shift): void
    {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => ShiftAssignmentStatus::Confirmed, 'assigned_by' => $this->actor->id, 'plan_revision' => $shift->revision]);
    }

    public function test_credentials_are_encrypted_masked_and_blank_save_preserves_them(): void
    {
        $stored = Setting::getValueUncached('routing', 'tomtom');
        $this->assertStringNotContainsString('test-credential', json_encode($stored));
        $this->assertArrayNotHasKey('api_key_encrypted', TomTomSettings::all());
        $this->assertStringNotContainsString('test-credential', json_encode(TomTomSettings::all()));
        TomTomSettings::save(TomTomSettings::DEFAULTS, '');
        $this->assertSame('test-credential-012345', TomTomSettings::apiKey());
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), null, true);
        $this->assertNull(TomTomSettings::apiKey());
        $this->assertFalse(TomTomSettings::all()['enabled']);
        Http::assertNothingSent();
    }

    public function test_summary_deduplicates_requests_and_never_returns_geometry(): void
    {
        $this->fakeRoute();
        $result = app(TomTomRoutingService::class)->summaryMany(['a' => $this->request(), 'b' => $this->request()]);
        $this->assertSame(1800, $result['a']['duration_seconds']);
        $this->assertSame($result['a'], $result['b']);
        $this->assertArrayNotHasKey('points', $result['a']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['travelMode'] === 'car' && $request['routeRepresentation'] === 'summaryOnly' && $request['departAt'] === now()->addHour()->utc()->toIso8601String());
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_geometry_is_a_separate_counted_on_demand_request(): void
    {
        $this->fakeRoute();
        $request = $this->request();
        $result = app(TomTomRoutingService::class)->geometry(...array_values($request));
        $this->assertCount(2, $result['points']);
        Http::assertSent(fn ($request) => $request['routeRepresentation'] === 'polyline');
    }

    public function test_conditional_budget_update_cannot_cross_the_cap_and_key_changes_do_not_reset_it(): void
    {
        $this->enable(['routing_monthly_limit' => 2]);
        $usage = app(RoutingUsageService::class);
        $this->assertTrue($usage->reserve('routing_transactions', 2));
        $this->assertFalse($usage->reserve('routing_transactions'));
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true, 'routing_monthly_limit' => 2]), 'another-test-key');
        $this->assertFalse($usage->reserve('routing_transactions'));
        $this->assertSame(2, $usage->current()['routing_transactions']);
        $this->travelTo(now()->addMonthNoOverflow());
        $this->assertTrue($usage->reserve('routing_transactions'));
        $this->assertSame(1, $usage->current()['routing_transactions']);
    }

    public function test_failures_and_limits_are_nullable_nonblocking_and_still_count_the_attempt(): void
    {
        Http::fake(['api.tomtom.com/*' => Http::response(['error' => 'not returned to clients'], 503)]);
        $this->enable(['routing_monthly_limit' => 1]);
        $routing = app(TomTomRoutingService::class);
        $first = $routing->summaryMany(['a' => $this->request()])['a'];
        $second = $routing->summaryMany(['a' => $this->request()])['a'];
        $this->assertNull($first['duration_seconds']);
        $this->assertSame('budget_exhausted', $second['reason']);
        Http::assertSentCount(1);
    }

    public function test_provider_is_never_called_under_a_transaction(): void
    {
        $this->fakeRoute();
        DB::transaction(function (): void {
            $result = app(TomTomRoutingService::class)->summaryMany(['a' => $this->request()]);
            $this->assertSame('transaction_requires_preflight', $result['a']['reason']);
        });
        Http::assertNothingSent();
    }

    public function test_both_neighbors_require_drive_plus_reserve_plus_rest(): void
    {
        $this->fakeRoute();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $target = $this->shift();
        $this->reserve($this->shift('11:00', '12:00', 53));
        $detail = app(StaffEligibilityService::class)->assessDetailedMany($target, collect([$this->employee]))[$this->employee->id];
        $this->assertSame(['travel_before', 'travel_after'], array_column($detail['issues'], 'code'));
        $this->assertSame(65, $detail['travel']['before']['required_minutes']);
        $this->assertSame(5, $detail['travel']['after']['missing_minutes']);
        Http::assertSentCount(2);
    }

    public function test_same_confirmed_point_has_no_drive_or_reserve_and_no_provider_call(): void
    {
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00'));
        $detail = app(TravelAssessmentService::class)->assessMany($target, collect([$this->employee]))[$this->employee->id];
        $this->assertSame(0, $detail['travel']['before']['duration_seconds']);
        $this->assertSame(0, $detail['travel']['before']['reserve_minutes']);
        $this->assertSame(30, $detail['travel']['before']['required_minutes']);
        Http::assertNothingSent();
    }

    public function test_missing_site_and_provider_outage_warn_without_blocking(): void
    {
        $target = $this->shift();
        $before = $this->shift('07:00', '08:00', 51);
        $before->forceFill(['location_snapshot' => null])->save();
        $this->reserve($before);
        $detail = app(TravelAssessmentService::class)->assessMany($target, collect([$this->employee]))[$this->employee->id];
        $this->assertSame([], $detail['issues']);
        $this->assertSame('site_unconfirmed', $detail['warnings'][0]['reason']);
        $this->assertNull($detail['travel']['before']['duration_seconds']);
        Http::assertNothingSent();
    }

    public function test_virtual_replacement_of_excluded_shift_still_is_a_travel_neighbor(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $old = $this->shift('07:00', '08:00', 51);
        $this->reserve($old);
        $virtual = clone $old;
        $detail = app(TravelAssessmentService::class)->assessMany($target, collect([$this->employee]), ['exclude_shift_ids' => [$old->id], 'additional_shifts' => [$virtual]])[$this->employee->id];
        $this->assertSame('travel_before', $detail['issues'][0]['code']);
        Http::assertSentCount(1);
    }

    public function test_valid_preflight_is_reused_inside_locks_and_changed_inputs_are_rejected(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $service = app(TravelAssessmentService::class);
        $preflight = $service->preflight($target, collect([$this->employee]));
        DB::transaction(function () use ($service, $target, $preflight): void {
            $detail = $service->assessMany($target, collect([$this->employee]), ['travel_preflight' => $preflight], true)[$this->employee->id];
            $this->assertSame('travel_before', $detail['issues'][0]['code']);
        });
        Http::assertSentCount(1);
        $target->forceFill(['revision' => $target->revision + 1])->save();
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $service->assessMany($target->fresh(), collect([$this->employee]), ['travel_preflight' => $preflight], true));
    }

    public function test_assignment_preflight_blocks_known_conflict_and_exception_audit_has_no_provider_metrics(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $assigner = app(ShiftAssignmentService::class);
        try {
            $assigner->assign($target, $this->employee, $this->actor);
            $this->fail('Known travel conflict must block normal assignment.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fahrzeit', $exception->getMessage());
        }
        $review = app(ShiftAssignmentExceptionService::class)->review($target, $this->employee, $this->actor);
        $this->assertTrue($review['can_override']);
        $assignment = $assigner->assign($target, $this->employee, $this->actor, ShiftAssignmentStatus::Confirmed, null, $target->revision, ['fingerprint' => $review['fingerprint'], 'reason' => 'Betriebliche Einsatzfolge ausdrücklich geprüft und ausreichend organisiert.', 'acknowledged' => true]);
        $audit = OperationAudit::where('subject_id', $assignment->id)->where('action', 'assignment.temporal_exception')->firstOrFail();
        $this->assertStringNotContainsString('duration_seconds', json_encode($audit->data));
        $this->assertStringNotContainsString('min fehlen', json_encode($audit->data));
    }

    public function test_first_candidate_pass_has_no_provider_io(): void
    {
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $issues = app(StaffEligibilityService::class)->assessMany($target, collect([$this->employee]), false, ['skip_routing' => true]);
        $this->assertSame([], $issues[$this->employee->id]);
        Http::assertNothingSent();
    }

    public function test_provider_outage_does_not_prevent_a_normal_assignment(): void
    {
        Http::fake(['api.tomtom.com/*' => Http::response([], 503)]);
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $assignment = app(ShiftAssignmentService::class)->assign($target, $this->employee, $this->actor);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignment->status);
        Http::assertSentCount(1);
    }

    public function test_stricter_neighbor_rule_profile_is_added_to_drive_and_reserve(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $own = new OperationsRuleProfile(['id' => 8, 'minimum_rest_minutes' => 20]);
        $neighbor = new OperationsRuleProfile(['id' => 9, 'minimum_rest_minutes' => 60]);
        $accounts = \Mockery::mock(WorkforceAccountService::class);
        $accounts->shouldReceive('ready')->andReturn(true);
        $accounts->shouldReceive('effectiveRulesForMoments')->andReturn(collect([$this->employee->id => ['target' => $own, 'before' => $neighbor]]));
        $this->app->instance(WorkforceAccountService::class, $accounts);
        $detail = app(TravelAssessmentService::class)->assessMany($target, collect([$this->employee]))[$this->employee->id];
        $this->assertSame(60, $detail['travel']['before']['rest_minutes']);
        $this->assertSame(95, $detail['travel']['before']['required_minutes']);
    }

    public function test_gps_arrival_is_advisory_only_and_geometry_rechecks_the_reader(): void
    {
        $this->enable(['use_employee_locations' => true]);
        $this->fakeRoute(3600);
        $target = $this->shift('06:30', '07:30');
        $point = ['latitude' => 53.0, 'longitude' => 13.0, 'accuracy_m' => 12, 'measured_at' => now()->subMinutes(5)->toIso8601String()];
        $reader = \Mockery::mock(EmployeeLocationReader::class);
        $reader->shouldReceive('latestForMany')->twice()->andReturn([$this->employee->id => ['status' => 'available', 'reason' => null, 'point' => $point]]);
        $reader->shouldReceive('latestForMany')->once()->andReturn([$this->employee->id => ['status' => 'consent_missing', 'reason' => 'Freigabe widerrufen', 'point' => null]]);
        $this->app->instance(EmployeeLocationReader::class, $reader);
        $service = app(TravelAssessmentService::class);
        $detail = $service->assessMany($target, collect([$this->employee]), ['include_employee_location' => true, 'actor' => $this->actor])[$this->employee->id];
        $this->assertTrue($detail['arrival']['late_hint']);
        $this->assertSame('jetzt', $detail['arrival']['departure_assumption']);
        $this->assertSame([], $detail['issues']);
        $geometry = $service->geometryFor($target, $this->employee, 'arrival', ['actor' => $this->actor]);
        $this->assertSame('unknown', $geometry['status']);
        $this->assertSame('Freigabe widerrufen', $geometry['reason']);
        Http::assertSentCount(1);
    }

    public function test_stale_or_imprecise_points_never_trigger_arrival_routing(): void
    {
        $this->enable(['use_employee_locations' => true]);
        $target = $this->shift('06:30', '07:30');
        foreach ([['accuracy_m' => 51, 'measured_at' => now()], ['accuracy_m' => 10, 'measured_at' => now()->subMinutes(16)]] as $invalid) {
            $point = ['latitude' => 53.0, 'longitude' => 13.0, 'accuracy_m' => $invalid['accuracy_m'], 'measured_at' => $invalid['measured_at']->toIso8601String()];
            $reader = \Mockery::mock(EmployeeLocationReader::class);
            $reader->shouldReceive('latestForMany')->once()->andReturn([$this->employee->id => ['status' => 'available', 'reason' => null, 'point' => $point]]);
            $this->app->instance(EmployeeLocationReader::class, $reader);
            $detail = app(TravelAssessmentService::class)->assessMany($target, collect([$this->employee]), ['include_employee_location' => true, 'actor' => $this->actor])[$this->employee->id];
            $this->assertSame('unknown', $detail['arrival']['status']);
            $this->assertSame([], $detail['issues']);
        }
        Http::assertNothingSent();
    }

    public function test_route_geometry_for_planned_leg_is_lazy_without_a_summary_request(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $route = app(TravelAssessmentService::class)->geometryFor($target, $this->employee, 'before');
        $this->assertSame('known', $route['status']);
        $this->assertCount(2, $route['points']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['routeRepresentation'] === 'polyline');
    }

    public function test_variant_reuses_preflight_after_saved_revisions_without_http_under_locks(): void
    {
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 300, 'lengthInMeters' => 1000]]]]);
        });
        $first = $this->shift('09:00', '10:00', 51);
        $second = $this->shift('11:00', '12:00', 53);
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$first->id, $second->id], 'Routing-Folge', 0, $this->actor);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-12', 'until' => '2027-05-12'];
        foreach ($data['entries'] as &$entry) {
            $entry['user_ids'] = [$this->employee->id];
        }
        unset($entry);
        $variant = $service->save($variant->id, 1, $data, $this->actor);
        $preview = $service->preview($variant, $this->actor);
        $this->assertTrue($preview['valid']);
        $service->approve($variant->id, 2, $preview['fingerprint'], $this->actor);
        $ids = $service->apply($variant->id, 3, $this->actor);
        $this->assertCount(2, $ids);
        $this->assertSame(2, ShiftAssignment::where('user_id', $this->employee->id)->blocking()->count());
    }

    public function test_many_neighbor_profile_instants_are_batched_and_use_each_work_model_timezone(): void
    {
        Schema::create('employee_work_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->string('starts_on');
            $table->string('ends_on')->nullable();
            $table->string('timezone');
        });
        Schema::create('employee_rule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('operations_rule_profile_id');
            $table->string('starts_on');
            $table->string('ends_on')->nullable();
        });
        $old = OperationsRuleProfile::firstOrFail();
        $new = OperationsRuleProfile::create(['name' => 'Ab Folgetag', 'minimum_rest_minutes' => 90, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()]);
        $users = User::factory()->count(24)->create(['role' => 'staff', 'status' => true]);
        $moments = [];
        foreach ($users as $index => $user) {
            DB::table('employee_work_models')->insert(['user_id' => $user->id, 'status' => 'active', 'starts_on' => '2027-05-01', 'timezone' => $index % 2 === 0 ? 'Europe/Berlin' : 'America/New_York']);
            DB::table('employee_rule_assignments')->insert([
                ['user_id' => $user->id, 'operations_rule_profile_id' => $old->id, 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-12'],
                ['user_id' => $user->id, 'operations_rule_profile_id' => $new->id, 'starts_on' => '2027-05-13', 'ends_on' => null],
            ]);
            $moments[$user->id] = ['target' => CarbonImmutable::parse('2027-05-12T21:00:00Z'), 'neighbor' => CarbonImmutable::parse('2027-05-12T23:30:00Z')];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $profiles = app(WorkforceAccountService::class)->effectiveRulesForMoments($users, $moments, $old);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, $queries);
        foreach ($users as $index => $user) {
            $this->assertSame($old->id, $profiles[$user->id]['target']->id);
            $this->assertSame($index % 2 === 0 ? $new->id : $old->id, $profiles[$user->id]['neighbor']->id);
        }
    }

    public function test_calculation_deadline_leaves_unsent_routes_unreserved(): void
    {
        Http::fake(function () {
            usleep(40000);

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 300, 'lengthInMeters' => 1000]]]]);
        });
        $requests = [];
        for ($index = 0; $index < 6; $index++) {
            $requests[$index] = $this->request();
            $requests[$index]['origin']['latitude'] += $index;
        }
        $result = (new TomTomRoutingService(0.1))->summaryMany($requests);
        $this->assertSame('provider_timeout', $result[5]['reason']);
        $this->assertNull($result[5]['duration_seconds']);
        Http::assertSentCount(3);
        $this->assertSame(3, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_consent_revoked_during_provider_request_discards_arrival_and_geometry(): void
    {
        $this->enable(['use_employee_locations' => true]);
        $target = $this->shift('06:30', '07:30');
        $point = ['latitude' => 53.0, 'longitude' => 13.0, 'accuracy_m' => 12, 'measured_at' => now()->toIso8601String()];
        $withdrawn = false;
        $reader = \Mockery::mock(EmployeeLocationReader::class);
        $reader->shouldReceive('latestForMany')->andReturnUsing(function () use (&$withdrawn, $point) {
            return [$this->employee->id => $withdrawn ? ['status' => 'consent_missing', 'reason' => 'Widerrufen', 'point' => null] : ['status' => 'available', 'reason' => null, 'point' => $point]];
        });
        $this->app->instance(EmployeeLocationReader::class, $reader);
        Http::fake(function () use (&$withdrawn) {
            $withdrawn = true;

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 300, 'lengthInMeters' => 1000], 'legs' => [['points' => [['latitude' => 52, 'longitude' => 13], ['latitude' => 53, 'longitude' => 14]]]]]]]);
        });
        $service = app(TravelAssessmentService::class);
        $arrival = $service->assessMany($target, collect([$this->employee]), ['include_employee_location' => true, 'actor' => $this->actor])[$this->employee->id]['arrival'];
        $this->assertSame('unknown', $arrival['status']);
        $this->assertArrayNotHasKey('duration_seconds', $arrival);
        $withdrawn = false;
        $geometry = $service->geometryFor($target, $this->employee, 'arrival', ['actor' => $this->actor]);
        $this->assertSame('unknown', $geometry['status']);
        $this->assertArrayNotHasKey('points', $geometry);
    }

    public function test_real_ai_application_rechecks_travel_before_its_outer_transaction(): void
    {
        $this->fakeRoute();
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        $result = app(AiAssistService::class)->apply([[$target->id, $this->employee->id, $target->revision]], $this->actor);
        $this->assertSame([], $result['done']);
        $this->assertStringContainsString('Fahrzeit', $result['failed'][0]);
        $this->assertFalse(ShiftAssignment::where('shift_id', $target->id)->exists());
        Http::assertSentCount(1);
    }

    public function test_bulk_assignment_checks_virtual_route_before_any_write(): void
    {
        (require database_path('migrations/2026_10_06_100000_create_planning_enhancements.php'))->up();
        $before = $this->shift('07:00', '08:00', 51);
        $after = $this->shift();
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 1800, 'lengthInMeters' => 1000]]]]);
        });
        try {
            app(PlanningEnhancementService::class)->bulkAssign([
                ['shift_id' => $before->id, 'revision' => $before->revision, 'user_ids' => [$this->employee->id]],
                ['shift_id' => $after->id, 'revision' => $after->revision, 'user_ids' => [$this->employee->id]],
            ], str_repeat('a', 64), $this->actor);
            $this->fail('The known route conflict must block the whole distribution.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Konflikte', $exception->getMessage());
        }
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertSentCount(2);
    }

    public function test_terminal_capture_checks_route_outside_locks_and_keeps_receipt_without_provider_metrics(): void
    {
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        (require database_path('migrations/2026_10_06_102000_create_operations_enhancements.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T09:00:00+02:00'));
        $target = $this->shift();
        $target->forceFill(['status' => 'open', 'published_revision' => $target->revision])->save();
        $this->reserve($target);
        $this->reserve($this->shift('07:00', '08:00', 51));
        $terminal = (string) Str::uuid();
        $service = app(WorkTimeTerminalService::class);
        $service->configure($this->employee, ['pin' => '729184', 'terminal_id' => $terminal, 'location_consent' => false], $this->employee);
        $capability = $service->authenticate($this->employee->id, '729184', $terminal, 'routing-terminal-qa');
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 1800, 'lengthInMeters' => 1000]]]]);
        });
        $receipt = $service->capture($capability['token'], $terminal, [
            'event_key' => (string) Str::uuid(), 'action' => 'start', 'revision' => 0,
            'assignment_id' => $target->assignments()->where('user_id', $this->employee->id)->firstOrFail()->id,
            'plan_revision' => $target->revision, 'work_context' => 'shift',
        ]);
        $this->assertSame('conflict', $receipt['status']);
        $this->assertStringContainsString('Fahrtkonflikt', $receipt['reason']);
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertStringNotContainsString('min fehlen', WorkTimeCaptureReceipt::firstOrFail()->reason);
        Http::assertSentCount(1);
    }

    public function test_nested_binding_without_typed_preflight_is_rejected_but_fresh_unknown_is_allowed(): void
    {
        $target = $this->shift();
        $this->reserve($this->shift('07:00', '08:00', 51));
        try {
            DB::transaction(fn () => app(ShiftAssignmentService::class)->assign($target, $this->employee, $this->actor));
            $this->fail('An outer transaction must supply its fresh server preflight.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fahrzeitprüfung fehlt', $exception->getMessage());
        }
        $this->assertFalse(ShiftAssignment::where('shift_id', $target->id)->exists());
        Http::assertNothingSent();
        try {
            DB::transaction(function () use ($target) {
                $invalid = app(TravelAssessmentService::class)->preflight($target, collect([$this->employee]));
                app(ShiftAssignmentService::class)->assign($target, $this->employee, $this->actor, ShiftAssignmentStatus::Requested, null, $target->revision, null, $invalid);
            });
            $this->fail('An unverified typed object made under locks cannot authorize binding.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fahrzeitprüfung fehlt', $exception->getMessage());
        }
        Http::assertNothingSent();
        Http::fake(['api.tomtom.com/*' => Http::response([], 503)]);
        $preflight = app(TravelAssessmentService::class)->preflight($target, collect([$this->employee]));
        $assignment = DB::transaction(fn () => app(ShiftAssignmentService::class)->assign($target, $this->employee, $this->actor, ShiftAssignmentStatus::Requested, null, $target->revision, null, $preflight));
        $this->assertSame(ShiftAssignmentStatus::Requested, $assignment->status);
        Http::assertSentCount(1);
    }

    public function test_locked_schedule_assessment_cannot_skip_required_preflight(): void
    {
        $target = $this->shift();
        $this->reserve($target);
        try {
            DB::transaction(fn () => app(StaffEligibilityService::class)->assessMany($target, collect([$this->employee]), true, ['skip_routing' => true]));
            $this->fail('Binding schedule rechecks cannot bypass routing with a preview flag.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Fahrzeitprüfung fehlt', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_audited_travel_pair_grant_can_publish_and_answer_both_duties_without_http_under_locks(): void
    {
        $before = $this->shift('07:00', '08:00', 51);
        $this->reserve($before);
        $beforeAssignment = $before->assignments()->sole();
        $after = $this->shift();
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => 1800, 'lengthInMeters' => 1000]]]]);
        });
        $exceptions = app(ShiftAssignmentExceptionService::class);
        $review = $exceptions->review($after, $this->employee, $this->actor);
        $this->assertTrue($review['can_override']);
        $this->assertSame($beforeAssignment->id, $review['conflicts'][0]['assignment_id']);
        $afterAssignment = app(ShiftAssignmentService::class)->assign($after, $this->employee, $this->actor, ShiftAssignmentStatus::Requested, null, $after->revision, [
            'fingerprint' => $review['fingerprint'], 'acknowledged' => true,
            'reason' => 'Geprüfte Ausnahmekoordination dieser beiden konkreten Dienste.',
        ]);
        $publication = app(PlanPublicationService::class);
        $publication->publish($before, $before->revision, $this->actor);
        $publication->publish($after, $after->revision, $this->actor);
        $publication->respond($beforeAssignment->id, $before->revision, true, $this->employee);
        $publication->respond($afterAssignment->id, $after->revision, true, $this->employee);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $beforeAssignment->fresh()->status);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $afterAssignment->fresh()->status);
        $audit = OperationAudit::where('action', 'assignment.temporal_exception')->sole();
        $this->assertStringNotContainsString('duration_seconds', json_encode($audit->data));
        $this->assertStringNotContainsString('min fehlen', json_encode($audit->data));

        $fresh = $before->fresh();
        $stale = app(TravelAssessmentService::class)->preflight($fresh, collect([$this->employee]));
        $after->forceFill(['revision' => $after->revision + 1])->save();
        try {
            DB::transaction(fn () => $exceptions->assertEligibleForAssignment($fresh, $this->employee, $beforeAssignment->fresh(), ['travel_preflight' => $stale]));
            $this->fail('A changed pair basis cannot keep using the old preflight or grant.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('geändert', $exception->getMessage());
        }
    }

    public function test_normal_preflight_does_not_prepare_peer_routes_without_a_pair_grant(): void
    {
        $before = $this->shift('07:00', '08:00', 51);
        $after = $this->shift();
        $this->reserve($before);
        $this->reserve($after);
        $this->fakeRoute(300);
        $preflight = app(TravelAssessmentService::class)->preflight($before, collect([$this->employee]));
        $this->assertSame([], $preflight->peerExceptions);
        Http::assertSentCount(1);
    }
}
