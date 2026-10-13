<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PlanVariant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffRegionalPreference;
use App\Models\User;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiPlanningService;
use App\Services\Operations\DeterministicPlanOptimizer;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RegionalPlanningConsistencyTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $anna;

    private User $zoe;

    private Shift $shift;

    private string $directory;

    private array $previousViewEnvironment;

    private array $forbiddenAiValues = [];

    public function createApplication(): Application
    {
        $this->directory = dirname(__DIR__, 2).'/storage/framework/testing/regional-planning-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/views', 0700, true);
        $this->previousViewEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        putenv('VIEW_COMPILED_PATH='.$this->directory.'/views');
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->directory.'/views';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_220000_create_staff_regional_preferences_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('shifts', function (Blueprint $table): void {
            $table->json('disposition_details')->nullable();
            $table->json('location_snapshot')->nullable();
        });
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Regional Fixture', 'role' => 'staff', 'status' => true]);
        $this->zoe = User::factory()->create(['name' => 'Zoe Regional Fixture', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Regional fixture rules', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Regional fixture', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Regional test service', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T00:00:00+02:00', 'ends_at' => '2027-05-14T00:00:00+02:00', 'required_staff' => 8, 'created_by' => $this->actor->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Regional test duty', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00:00+02:00', 'ends_at' => '2027-05-13T16:00:00+02:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'location_name' => 'München', 'location_snapshot' => ['name' => 'München confirmed fixture', 'latitude' => 48.137, 'longitude' => 11.575, 'confirmed' => true, 'revision' => 1], 'created_by' => $this->actor->id])->fresh();
        $this->actingAs($this->actor);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with(str_replace('\\', '/', $this->directory), str_replace('\\', '/', storage_path('framework/testing/regional-planning-')))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
        [$original, $env, $server] = $this->previousViewEnvironment;
        putenv($original === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$original);
        if ($env === null) {
            unset($_ENV['VIEW_COMPILED_PATH']);
        } else {
            $_ENV['VIEW_COMPILED_PATH'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['VIEW_COMPILED_PATH']);
        } else {
            $_SERVER['VIEW_COMPILED_PATH'] = $server;
        }
    }

    private function region(User $user, string $tier): void
    {
        $states = ['preferred' => [], 'possible' => [], 'emergency' => []];
        $states[$tier === 'outside' ? 'preferred' : $tier] = [$tier === 'outside' ? 'DE-HH' : 'DE-BY'];
        $service = app(StaffRegionalPreferenceService::class);
        $service->save($user, $this->actor, ['schema_version' => 2, 'enabled' => true, 'base_location' => '', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'emergency_radius_km' => null, 'federal_states' => $states, 'no_go_areas' => []], $service->get($user)['revision']);
    }

    private function optimize(): array
    {
        return app(DeterministicPlanOptimizer::class)->preview('2027-05-13', '2027-05-13', ['wish_weight' => 30, 'load_weight' => 1, 'night_weight' => 10, 'weekend_weight' => 10, 'night_start' => '22:00', 'night_end' => '06:00'], $this->actor);
    }

    private function fakeAi(?callable $during = null): void
    {
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('structured')->once()->andReturnUsing(function ($task, $messages) use ($during) {
            $this->assertStringNotContainsString('Regional Fixture', $messages[1]['content']);
            $this->assertStringNotContainsString('base_point', $messages[1]['content']);
            foreach ($this->forbiddenAiValues as $value) {
                $this->assertStringNotContainsString($value, $messages[1]['content']);
            }
            if ($during) {
                $during();
            }

            return new OpenRouterChatResponse(json_encode(['summary' => 'Synthetic regional proposal', 'recommendations' => [['shift_id' => $this->shift->id, 'user_ids' => [$this->anna->id], 'reason' => 'Synthetic explanation']], 'warnings' => []], JSON_THROW_ON_ERROR));
        });
        $this->app->instance(AiDispositionClient::class, $client);
    }

    private function assertChanged(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a stale regional basis to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('geändert', implode(' ', $exception->errors()['aiPlanning']));
        }
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, PlanVariant::count());
        Http::assertNothingSent();
    }

    public function test_optimizer_prioritizes_regional_suitability_then_preserves_existing_wish_costs(): void
    {
        $this->region($this->anna, 'outside');
        $this->region($this->zoe, 'preferred');
        EmployeeAvailability::create(['user_id' => $this->anna->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'timezone' => 'Europe/Berlin', 'weekdays' => [4], 'whole_day' => true, 'revision' => 1]);
        $first = $this->optimize();
        $this->assertSame([$this->zoe->id], $first['entries'][0]['user_ids']);
        $this->assertSame(15, $first['rows'][0]['proposals'][0]['regional_adjustment']);
        $this->assertSame('preferred', $first['rows'][0]['proposals'][0]['regional_state']);
        $this->region($this->anna, 'preferred');
        $next = $this->optimize();
        $this->assertSame([$this->anna->id], $next['entries'][0]['user_ids']);
        $this->assertLessThan($first['rows'][0]['proposals'][0]['score'], $next['rows'][0]['proposals'][0]['score']);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_optimizer_orders_all_soft_tiers_and_keeps_no_go_hard(): void
    {
        $this->region($this->anna, 'outside');
        $this->region($this->zoe, 'preferred');
        $possible = User::factory()->create(['role' => 'staff', 'status' => true]);
        $emergency = User::factory()->create(['role' => 'staff', 'status' => true]);
        $neutral = User::factory()->create(['role' => 'staff', 'status' => true]);
        $blocked = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->region($possible, 'possible');
        $this->region($emergency, 'emergency');
        app(StaffRegionalPreferenceService::class)->save($blocked, $this->actor, ['enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]], 0);
        $this->shift->update(['required_staff' => 6]);
        $preview = $this->optimize();
        $this->assertSame([$this->zoe->id, $possible->id, $neutral->id, $emergency->id, $this->anna->id], $preview['entries'][0]['user_ids']);
        $this->assertSame([15, 5, 0, -10, -20], array_column($preview['rows'][0]['proposals'], 'regional_adjustment'));
        $this->assertSame(1, $preview['open']);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_optimizer_regional_reads_are_batched_for_the_population(): void
    {
        $this->region($this->anna, 'preferred');
        $countReads = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->optimize();
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'select') && str_contains($query['query'], 'from "staff_regional_preferences"'))->count();
            DB::disableQueryLog();

            return $queries;
        };
        $baseline = $countReads();
        User::factory()->count(18)->create(['role' => 'staff', 'status' => true]);
        $this->assertGreaterThan(0, $baseline);
        $this->assertSame($baseline, $countReads());
        Http::assertNothingSent();
    }

    public function test_ai_rejects_soft_region_changes_during_the_response_without_plan_changes(): void
    {
        $this->fakeAi(fn () => $this->region($this->zoe, 'preferred'));
        $this->assertChanged(fn () => app(AiPlanningService::class)->analyzeShift($this->shift->id, $this->actor));
    }

    public function test_confirmed_origin_address_and_coordinates_are_not_sent_to_the_ai(): void
    {
        $point = ['label' => 'Synthetic Secret Road 918, 80331 München', 'latitude' => 48.137123, 'longitude' => 11.575321, 'provider' => 'tomtom', 'provider_id' => 'synthetic-secret-origin', 'type' => 'Point Address', 'precision' => 'address'];
        app(StaffRegionalPreferenceService::class)->save($this->anna, $this->actor, ['schema_version' => 2, 'enabled' => true, 'base_location' => $point['label'], 'base_point' => $point, 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'emergency_radius_km' => null, 'federal_states' => ['preferred' => [], 'possible' => [], 'emergency' => []], 'no_go_areas' => []], 0);
        $this->forbiddenAiValues = [$point['label'], '48.137123', '11.575321', $point['provider_id']];
        $this->fakeAi();
        $proposal = app(AiPlanningService::class)->analyzeShift($this->shift->id, $this->actor);
        $this->assertNotEmpty($proposal['token']);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_ai_token_invalidates_after_region_creation_and_removal_in_the_same_second(): void
    {
        $service = app(AiPlanningService::class);
        $this->fakeAi();
        $proposal = $service->analyzeShift($this->shift->id, $this->actor);
        $this->region($this->zoe, 'preferred');
        $this->assertChanged(fn () => $service->selectCandidate($proposal['token'], $this->shift->id, $this->anna->id, $this->actor));
        $this->fakeAi();
        $proposal = $service->analyzeShift($this->shift->id, $this->actor);
        StaffRegionalPreference::where('user_id', $this->zoe->id)->delete();
        $this->assertChanged(fn () => $service->selectCandidate($proposal['token'], $this->shift->id, $this->anna->id, $this->actor));
    }

    public function test_timeline_cached_preview_changes_immediately_after_a_soft_region_edit(): void
    {
        $timeline = Livewire::actingAs($this->actor)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-13', 'planningEnabled' => true])->call('toggleSuggestions');
        $this->assertSame($this->anna->id, $timeline->viewData('planningPreview')['proposals']->first()['user']->id);
        $this->region($this->zoe, 'preferred');
        $timeline->call('$refresh')->assertHasNoErrors();
        $this->assertSame($this->zoe->id, $timeline->viewData('planningPreview')['proposals']->first()['user']->id);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_routing_enabled_timeline_drops_old_region_preview_before_lazy_reload(): void
    {
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'synthetic-regional-key');
        $timeline = Livewire::actingAs($this->actor)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-13', 'planningEnabled' => true])->call('toggleSuggestions')->call('loadSuggestions');
        $this->assertSame($this->anna->id, $timeline->viewData('planningPreview')['proposals']->first()['user']->id);
        $this->region($this->zoe, 'preferred');
        $timeline->call('$refresh')->assertSet('suggestionsReady', false);
        $this->assertTrue($timeline->viewData('planningPreview')['proposals']->isEmpty());
        $timeline->call('loadSuggestions')->assertSet('suggestionsReady', true);
        $this->assertSame($this->zoe->id, $timeline->viewData('planningPreview')['proposals']->first()['user']->id);
        Http::assertNothingSent();
    }
}
