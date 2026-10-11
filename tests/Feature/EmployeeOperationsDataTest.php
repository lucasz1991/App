<?php

namespace Tests\Feature;

use App\Livewire\Profile\EmployeeOperations;
use App\Models\CustomerPortalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\EmployeeOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeOperationsDataTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private EmployeeOperationsService $service;

    private int $orderId;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = dirname(__DIR__, 2).'/storage/framework/views/profile-operations-data-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::table('shifts', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_revision')->default(0);
            $table->json('location_snapshot')->nullable();
        });
        Schema::table('shift_assignments', fn (Blueprint $table) => $table->unsignedInteger('plan_revision')->default(1));
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $customerId = DB::table('customers')->insertGetId([
            'public_id' => (string) Str::uuid(), 'company_name' => 'Operational Customer',
            'email' => 'private-customer@example.invalid', 'notes' => 'SECRET-CUSTOMER-NOTE',
        ]);
        $this->orderId = DB::table('orders')->insertGetId([
            'public_id' => (string) Str::uuid(), 'customer_id' => $customerId,
            'title' => 'Operational Order', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-31 23:00:00',
            'notes' => 'SECRET-ORDER-NOTE',
        ]);
        $this->service = app(EmployeeOperationsService::class);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->compiledViews);
        parent::tearDown();
        putenv($this->previousCompiledEnvironment[0] === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$this->previousCompiledEnvironment[0]);
        foreach (['_ENV', '_SERVER'] as $index => $environment) {
            if ($this->previousCompiledEnvironment[$index + 1] === null) {
                unset($GLOBALS[$environment]['VIEW_COMPILED_PATH']);
            } else {
                $GLOBALS[$environment]['VIEW_COMPILED_PATH'] = $this->previousCompiledEnvironment[$index + 1];
            }
        }
    }

    public function test_the_read_uses_only_its_core_schema_and_missing_optional_modules_do_not_disable_it(): void
    {
        $this->assertFalse(Schema::hasTable('operation_inquiries'));
        $this->assertFalse(Schema::hasTable('employee_location_points'));
        $this->assertTrue($this->service->ready());
        $view = $this->service->read($this->employee->id);
        $this->assertSame('empty', $view['state']);
        $this->assertSame(['total' => 0, 'ongoing' => 0, 'upcoming' => 0, 'past' => 0, 'confirmed' => 0, 'requested' => 0], $view['summary']);
        $this->assertNull($view['records']);
    }

    public function test_missing_required_schema_is_explicit_and_stops_before_assignment_reads(): void
    {
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn('published_revision'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = $this->service->read($this->employee->id, 'shifts');
        $this->assertSame('unconfigured', $view['state']);
        $this->assertNull($view['records']);
        $this->assertSame([], $this->dataQueries());
        DB::disableQueryLog();
    }

    public function test_inactive_and_non_management_internal_users_are_denied_before_shift_reads(): void
    {
        foreach ([['role' => 'staff', 'status' => true], ['role' => 'admin', 'status' => false], ['role' => 'customer', 'status' => true]] as $attributes) {
            $actor = User::factory()->create($attributes);
            $this->actingAs($actor);
            $this->assertDenied(fn () => $this->service->read($this->employee->id));
        }
    }

    public function test_management_audience_and_both_permissions_are_freshly_checked(): void
    {
        [$manager, $team] = $this->manager();
        $this->actingAs($manager);
        $this->assertSame('empty', $this->service->read($this->employee->id)['state']);
        foreach (['employees.view', 'operations.manage'] as $ability) {
            $team->update(['rbac_permissions' => ['employees.view' => $ability !== 'employees.view', 'operations.manage' => $ability !== 'operations.manage']]);
            $this->assertDenied(fn () => $this->service->read($this->employee->id));
        }
        $team->update(['rbac_permissions' => ['employees.view' => true, 'operations.manage' => true], 'name' => 'Mitarbeiter']);
        $this->assertDenied(fn () => $this->service->read($this->employee->id));
        $team->update(['name' => 'Verwaltung']);
        $manager->update(['status' => false]);
        $this->assertDenied(fn () => $this->service->read($this->employee->id));
    }

    public function test_portal_identity_never_inherits_the_internal_administrator_with_the_same_id(): void
    {
        $portal = new CustomerPortalIdentity;
        $portal->forceFill(['id' => $this->admin->id, 'role' => 'admin', 'status' => true]);
        auth()->setUser($portal);
        $this->assertDenied(fn () => $this->service->read($this->employee->id));
        Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    public function test_target_must_remain_staff_but_inactive_staff_history_can_be_reviewed(): void
    {
        $this->assertDenied(fn () => $this->service->read($this->admin->id));
        $this->employee->update(['status' => false]);
        $this->assertSame('empty', $this->service->read($this->employee->id)['state']);
        $this->employee->update(['role' => 'customer']);
        $this->assertDenied(fn () => $this->service->read($this->employee->id));
    }

    public function test_summary_and_periods_use_exact_time_boundaries_and_separate_plan_from_history(): void
    {
        $current = $this->shift('2026-10-11T09:00:00Z', '2026-10-11T11:00:00Z');
        $startsNow = $this->shift('2026-10-11T10:00:00Z', '2026-10-11T11:00:00Z', ['assignment_status' => 'requested']);
        $next = $this->shift('2026-10-11T11:00:00Z', '2026-10-11T12:00:00Z');
        $endsNow = $this->shift('2026-10-11T09:00:00Z', '2026-10-11T10:00:00Z');
        $this->shift('2026-10-11T11:00:00Z', '2026-10-11T12:00:00Z', ['status' => 'completed']);
        $this->shift('2026-10-11T11:00:00Z', '2026-10-11T12:00:00Z', ['status' => 'cancelled']);
        $this->shift('2026-10-11T11:00:00Z', '2026-10-11T12:00:00Z', ['assignment_status' => 'declined']);
        $this->shift('2026-10-11T11:00:00Z', '2026-10-11T12:00:00Z', ['assignment_status' => 'cancelled']);
        $pastDeclined = $this->shift('2026-10-10T11:00:00Z', '2026-10-10T12:00:00Z', ['assignment_status' => 'declined']);
        $pastCancelled = $this->shift('2026-10-09T11:00:00Z', '2026-10-09T12:00:00Z', ['status' => 'cancelled', 'assignment_status' => 'cancelled']);
        $view = $this->service->read($this->employee->id);
        $this->assertSame(['total' => 10, 'ongoing' => 2, 'upcoming' => 1, 'past' => 3, 'confirmed' => 2, 'requested' => 1], $view['summary']);
        $this->assertSame([$current, $startsNow], array_column($view['current'], 'id'));
        $this->assertSame([$next], array_column($view['next'], 'id'));
        $this->assertSame([$endsNow], array_column($view['previous'], 'id'));
        $this->assertSame([$current, $startsNow, $next], array_column($this->page('upcoming')->items(), 'id'));
        $this->assertSame([$endsNow, $pastDeclined, $pastCancelled], array_column($this->page('past')->items(), 'id'));
        $this->assertCount(10, $this->page('all')->items());
        $this->assertSame('2026-10-11T10:00:00+00:00', $view['now_iso']);
    }

    public function test_current_and_next_cards_are_bounded_while_aggregate_counts_remain_complete(): void
    {
        foreach (range(1, 12) as $index) {
            $this->shift('2026-10-11T09:00:00Z', '2026-10-11T11:00:00Z');
            $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
            $this->shift('2026-10-10T09:00:00Z', '2026-10-10T11:00:00Z');
        }
        $view = $this->service->read($this->employee->id);
        $this->assertCount(3, $view['current']);
        $this->assertCount(3, $view['next']);
        $this->assertCount(1, $view['previous']);
        $this->assertSame(36, $view['summary']['total']);
        $this->assertSame(12, $view['summary']['ongoing']);
        $this->assertSame(12, $view['summary']['upcoming']);
    }

    public function test_only_exact_target_assignments_are_projected_and_orphan_or_deleted_shifts_are_excluded(): void
    {
        $own = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        $other = User::factory()->create(['role' => 'staff']);
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['user_id' => $other->id]);
        $deleted = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        DB::table('shifts')->where('id', $deleted)->update(['deleted_at' => '2026-10-11 10:00:00']);
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('shift_assignments')->insert(['shift_id' => 999999, 'user_id' => $this->employee->id, 'status' => 'confirmed']);
        DB::statement('PRAGMA foreign_keys = ON');
        $rows = $this->page('all')->items();
        $this->assertSame([$own], array_column($rows, 'id'));
        $this->assertSame(1, $this->service->read($this->employee->id)['summary']['total']);
        $this->assertArrayNotHasKey('user_id', $rows[0]);
        $this->assertArrayNotHasKey('assignees', $rows[0]);
    }

    public function test_pagination_is_stable_bounded_and_clamped_before_loading_rows(): void
    {
        $ids = [];
        foreach (range(1, 37) as $index) {
            $ids[] = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        }
        $first = $this->page('upcoming');
        $last = $this->page('upcoming', '', 999999999);
        $this->assertSame(array_slice($ids, 0, 15), array_column($first->items(), 'id'));
        $this->assertSame(array_slice($ids, 30), array_column($last->items(), 'id'));
        $this->assertSame(3, $last->currentPage());
        $this->assertSame(37, $last->total());
        $this->assertSame(EmployeeOperationsService::PAGE_NAME, $last->getPageName());
        $this->assertSame(1, $this->page('upcoming', '', -1)->currentPage());
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), array_column($this->page('all')->items(), 'id'));
    }

    public function test_search_matches_operational_columns_and_literal_wildcards_not_private_notes(): void
    {
        $match = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['title' => 'Abfertigung _ 100% !', 'role_name' => 'Wagenmeister', 'location_name' => 'Bahnhof München']);
        $this->shift('2026-10-13T09:00:00Z', '2026-10-13T11:00:00Z', ['title' => 'Anderer Dienst']);
        foreach (['Wagenmeister', 'München', '100%', '_ 100%', '!'] as $search) {
            $this->assertSame([$match], array_column($this->page('all', $search)->items(), 'id'));
        }
        $this->assertCount(2, $this->page('all', 'operational customer')->items());
        $this->assertCount(0, $this->page('all', 'SECRET-SHIFT-NOTE')->items());
        $this->assertCount(0, $this->page('all', 'SECRET-ORDER-NOTE')->items());
        $this->assertCount(0, $this->page('all', 'private-customer@example.invalid')->items());
        $this->assertCount(0, $this->page('all', "' OR 1=1 --")->items());
    }

    public function test_cross_midnight_and_dst_are_formatted_in_the_shift_zone_with_utc_instants(): void
    {
        $this->shift('2026-10-24T23:30:00Z', '2026-10-25T02:30:00Z');
        $this->shift('2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z');
        $this->shift('2026-10-10T20:00:00Z', '2026-10-11T04:00:00Z', ['timezone' => 'Europe/Berlin']);
        $rows = $this->page('all')->items();
        $autumn = $rows[0];
        $night = $rows[1];
        $spring = $rows[2];
        $this->assertSame(['01:30', '03:30', 3.0], [$autumn['start_time'], $autumn['end_time'], $autumn['duration_hours']]);
        $this->assertSame('2026-10-24T23:30:00+00:00', $autumn['starts_at']);
        $this->assertSame('2026-10-25T02:30:00+00:00', $autumn['ends_at']);
        $this->assertSame(['10.10.2026', '11.10.2026', '22:00', '06:00'], [$night['start_date'], $night['end_date'], $night['start_time'], $night['end_time']]);
        $this->assertSame(['01:30', '03:30', 1.0], [$spring['start_time'], $spring['end_time'], $spring['duration_hours']]);
        $this->assertStringContainsString('from=2026-10-10', $night['url']);
        $this->assertStringContainsString('until=2026-10-11', $night['url']);
        $this->assertStringContainsString('view=shifts', $night['url']);
    }

    public function test_saved_location_display_is_safe_and_never_guesses_or_exposes_coordinates(): void
    {
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['location_snapshot' => json_encode(['label' => 'Saved worksite', 'latitude' => 52.52, 'longitude' => 13.405, 'provider' => 'SECRET-PROVIDER'])]);
        $row = $this->page('all')->items()[0];
        $this->assertSame('Saved worksite', $row['location']);
        $json = json_encode($row);
        foreach (['latitude', 'longitude', 'SECRET-PROVIDER', 'SECRET-SHIFT-NOTE', 'SECRET-ASSIGNMENT-NOTE', 'SECRET-CUSTOMER-NOTE', 'SECRET-ORDER-NOTE', 'private-customer@example.invalid'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
    }

    public function test_shift_and_assignment_status_and_publication_revision_remain_distinct(): void
    {
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['status' => 'confirmed', 'assignment_status' => 'requested', 'revision' => 3, 'published_revision' => 2, 'plan_revision' => 2]);
        $this->shift('2026-10-13T09:00:00Z', '2026-10-13T11:00:00Z', ['revision' => 2, 'published_revision' => 2]);
        $this->shift('2026-10-14T09:00:00Z', '2026-10-14T11:00:00Z', ['revision' => 1, 'published_revision' => 0]);
        $rows = $this->page('upcoming')->items();
        $this->assertSame(['confirmed', 'requested', 'Bestätigt', 'Angefragt'], [$rows[0]['shift_status'], $rows[0]['assignment_status'], $rows[0]['shift_status_label'], $rows[0]['assignment_status_label']]);
        $this->assertFalse($rows[0]['published']);
        $this->assertSame('Unveröffentlichte Änderung', $rows[0]['publication_label']);
        $this->assertSame([3, 2, 2], [$rows[0]['revision'], $rows[0]['published_revision'], $rows[0]['plan_revision']]);
        $this->assertTrue($rows[0]['assignment_published_basis']);
        $this->assertFalse($rows[0]['assignment_current_plan']);
        $this->assertSame('Zuordnung zur veröffentlichten Revision 2; aktueller Entwurf ist Revision 3.', $rows[0]['assignment_revision_note']);
        $this->assertTrue($rows[1]['published']);
        $this->assertSame('Veröffentlicht', $rows[1]['publication_label']);
        $this->assertFalse($rows[2]['published']);
        $this->assertSame('Entwurf', $rows[2]['publication_label']);
        $this->assertFalse($rows[2]['assignment_published_basis']);
        $this->assertSame('Keine veröffentlichte Planbasis für diese Zuordnung.', $rows[2]['assignment_revision_note']);
    }

    public function test_earlier_response_revision_is_labelled_without_changing_original_assignment_status(): void
    {
        $id = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['assignment_status' => 'confirmed', 'revision' => 3, 'published_revision' => 3, 'plan_revision' => 2]);
        $row = $this->page('upcoming')->items()[0];
        $this->assertSame($id, $row['id']);
        $this->assertSame('confirmed', $row['assignment_status']);
        $this->assertFalse($row['assignment_current_plan']);
        $this->assertFalse($row['assignment_published_basis']);
        $this->assertSame('Zuordnung zu Planrevision 2; veröffentlicht ist Revision 3.', $row['assignment_revision_note']);
    }

    public function test_navigation_window_uses_planning_display_zone_not_the_shift_local_date(): void
    {
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $this->shift('2026-10-12T01:00:00Z', '2026-10-12T04:00:00Z', ['timezone' => 'America/New_York']);
        $row = $this->page('upcoming')->items()[0];
        $this->assertSame('11.10.2026', $row['start_date']);
        $this->assertSame('21:00', $row['start_time']);
        $this->assertSame('America/New_York', $row['timezone']);
        $this->assertStringContainsString('from=2026-10-12', $row['url']);
        $this->assertStringContainsString('until=2026-10-12', $row['url']);
    }

    public function test_query_count_does_not_grow_with_page_records_and_reads_have_no_side_effects(): void
    {
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->page('upcoming');
        $one = count($this->dataQueries());
        DB::disableQueryLog();
        foreach (range(1, 35) as $index) {
            $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(15, $this->page('upcoming')->items());
        $many = DB::getQueryLog();
        $this->assertSame($one, count($this->dataQueries()));
        $this->assertLessThanOrEqual(17, count($many));
        foreach ($many as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
            $this->assertStringNotContainsString('user_profiles', $query['query']);
            $this->assertStringNotContainsString('employee_location', $query['query']);
        }
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    public function test_configured_backend_failure_is_not_returned_as_empty_state(): void
    {
        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (preg_match('/from "shifts"/i', $query)) {
                throw new QueryException('sqlite', $query, $bindings, new \PDOException('Isolated synthetic failure'));
            }
        });
        $this->expectException(QueryException::class);
        $this->page('all');
    }

    public function test_invalid_stored_status_is_not_hidden_as_an_empty_employee_history(): void
    {
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['status' => 'invalid_status']);
        $this->expectException(\ValueError::class);
        $this->page('all');
    }

    public function test_missing_legacy_dates_stay_neutral_without_inventing_schedule_or_navigation_dates(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dateTime('starts_at')->nullable()->change();
            $table->dateTime('ends_at')->nullable()->change();
        });
        $id = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        DB::table('shifts')->where('id', $id)->update(['starts_at' => null, 'ends_at' => null]);
        $row = $this->page('all')->items()[0];
        $this->assertFalse($row['dates_available']);
        $this->assertSame('Zeitraum nicht hinterlegt', $row['date_label']);
        $this->assertSame(['—', '—', '—', '—'], [$row['start_date'], $row['end_date'], $row['start_time'], $row['end_time']]);
        $this->assertNull($row['starts_at']);
        $this->assertNull($row['ends_at']);
        $this->assertNull($row['duration_hours']);
        $this->assertFalse($row['ongoing']);
        $this->assertFalse($row['upcoming']);
        $this->assertFalse($row['past']);
        $this->assertStringNotContainsString('from=', $row['url']);
        $this->assertStringNotContainsString('until=', $row['url']);
        $this->assertSame([], $this->service->read($this->employee->id)['current']);
        $this->assertSame([], $this->service->read($this->employee->id)['next']);
        DB::table('shifts')->where('id', $id)->update(['starts_at' => null, 'ends_at' => '2026-10-12 11:00:00']);
        $this->assertSame(0, $this->service->read($this->employee->id)['summary']['upcoming']);
        $this->assertCount(0, $this->page('upcoming')->items());
        DB::table('shifts')->where('id', $id)->update(['starts_at' => '2026-10-12 11:00:00', 'ends_at' => '2026-10-12 11:00:00']);
        $this->assertSame(0, $this->service->read($this->employee->id)['summary']['upcoming']);
        $this->assertCount(0, $this->page('upcoming')->items());
        DB::table('shifts')->where('id', $id)->update(['starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-12 11:00:00']);
        $this->assertSame(0, $this->service->read($this->employee->id)['summary']['upcoming']);
        $this->assertCount(0, $this->page('upcoming')->items());
        DB::table('shifts')->where('id', $id)->update(['starts_at' => '2026-02-30 10:00:00', 'ends_at' => 'not-a-date']);
        $row = $this->page('all')->items()[0];
        $this->assertFalse($row['dates_available']);
        $this->assertNull($row['starts_at']);
        $this->assertNull($row['ends_at']);
    }

    public function test_native_detail_url_is_guarded_by_exact_assignment_and_current_rights(): void
    {
        $own = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        $other = User::factory()->create(['role' => 'staff']);
        $foreign = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['user_id' => $other->id]);
        $this->assertStringContainsString('shift='.$own, $this->service->shiftUrl($this->employee->id, $own));
        $this->assertHttp(404, fn () => $this->service->shiftUrl($this->employee->id, $foreign));
        DB::table('shifts')->where('id', $own)->update(['deleted_at' => '2026-10-11 10:00:00']);
        $this->assertHttp(404, fn () => $this->service->shiftUrl($this->employee->id, $own));
        $this->admin->update(['status' => false]);
        $this->assertDenied(fn () => $this->service->shiftUrl($this->employee->id, $foreign));
    }

    public function test_invalid_filters_and_view_mode_are_rejected_before_shift_data_queries(): void
    {
        foreach ([['tomorrow', ''], ['all', str_repeat('x', 121)], ['all', "bad\0query"]] as [$period, $search]) {
            try {
                $this->service->read($this->employee->id, 'shifts', $period, $search);
                $this->fail('Invalid filter must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertHttp(422, fn () => $this->service->read($this->employee->id, 'invalid'));
    }

    public function test_component_keeps_records_out_of_public_state_and_ignores_page_query_parameters(): void
    {
        $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z', ['title' => 'PRIVATE-RENDER-TITLE']);
        $component = Livewire::withQueryParams(['employeeShiftsPage' => 9, 'page' => 3])->test(EmployeeOperations::class, ['userId' => $this->employee->id, 'viewMode' => 'shifts']);
        $component->assertSet('paginators.employeeShiftsPage', 1);
        $state = $component->snapshot['data'];
        $this->assertSame(['userId', 'viewMode', 'period', 'search', 'paginators'], array_keys($state));
        $this->assertStringNotContainsString('PRIVATE-RENDER-TITLE', json_encode($state));
        $this->assertStringNotContainsString('SECRET-SHIFT-NOTE', $component->html());
    }

    public function test_component_locked_target_cannot_be_changed(): void
    {
        $component = Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('userId', $this->admin->id);
    }

    public function test_component_locked_view_cannot_be_changed(): void
    {
        $component = Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('viewMode', 'shifts');
    }

    public function test_component_actions_recheck_rights_and_named_pagination_resets_and_clamps(): void
    {
        foreach (range(1, 31) as $index) {
            $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        }
        $component = Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id, 'viewMode' => 'shifts']);
        $component->call('setPage', 99999, EmployeeOperationsService::PAGE_NAME)->assertSet('paginators.employeeShiftsPage', 3)
            ->call('setSearch', 'Operational Customer')->assertSet('paginators.employeeShiftsPage', 1)
            ->call('nextPage', EmployeeOperationsService::PAGE_NAME)->assertSet('paginators.employeeShiftsPage', 2)
            ->call('setPeriod', 'all')->assertSet('paginators.employeeShiftsPage', 1)
            ->call('nextPage', EmployeeOperationsService::PAGE_NAME)->call('refreshOperations')->assertSet('paginators.employeeShiftsPage', 1);
        $this->admin->update(['status' => false]);
        $component->call('refreshOperations')->assertForbidden();
    }

    public function test_component_rejects_invalid_page_names_values_and_filter_input(): void
    {
        $component = Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id, 'viewMode' => 'shifts']);
        foreach ([[0, EmployeeOperationsService::PAGE_NAME], ['x', EmployeeOperationsService::PAGE_NAME], [1000001, EmployeeOperationsService::PAGE_NAME], [1, 'page'], [1, 'locationHistoryPage']] as [$page, $name]) {
            $component->call('setPage', $page, $name)->assertHasErrors('employeeShiftsPage')->assertSet('paginators.employeeShiftsPage', 1);
        }
        $component->set('period', 'invalid')->assertHasErrors('period')->assertSet('period', 'upcoming');
        $component->set('search', str_repeat('x', 121))->assertHasErrors('search')->assertSet('search', '');
        $component->set('paginators.otherPage', 3)->assertHasErrors('employeeShiftsPage');
    }

    public function test_component_native_open_shift_redirect_uses_only_target_assignments(): void
    {
        $id = $this->shift('2026-10-12T09:00:00Z', '2026-10-12T11:00:00Z');
        Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id, 'viewMode' => 'shifts'])
            ->call('openShift', $id)->assertRedirect($this->service->shiftUrl($this->employee->id, $id));
        Livewire::test(EmployeeOperations::class, ['userId' => $this->employee->id, 'viewMode' => 'shifts'])->call('openShift', 999999)->assertNotFound();
    }

    private function page(string $period, string $search = '', int $page = 1): LengthAwarePaginator
    {
        return $this->service->read($this->employee->id, 'shifts', $period, $search, $page)['records'];
    }

    private function shift(string $start, string $end, array $override = []): int
    {
        $userId = $override['user_id'] ?? $this->employee->id;
        $assignmentStatus = $override['assignment_status'] ?? 'confirmed';
        $planRevision = $override['plan_revision'] ?? 1;
        unset($override['user_id'], $override['assignment_status'], $override['plan_revision']);
        $id = DB::table('shifts')->insertGetId(array_replace([
            'order_id' => $this->orderId, 'public_id' => (string) Str::uuid(), 'title' => 'Planned shift', 'role_name' => 'WSU',
            'starts_at' => CarbonImmutable::parse($start)->utc()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($end)->utc()->format('Y-m-d H:i:s'),
            'timezone' => 'Europe/Berlin', 'location_name' => 'Treuchlingen', 'status' => 'confirmed',
            'revision' => 1, 'published_revision' => 1, 'notes' => 'SECRET-SHIFT-NOTE',
        ], $override));
        DB::table('shift_assignments')->insert(['shift_id' => $id, 'user_id' => $userId, 'status' => $assignmentStatus, 'plan_revision' => $planRevision, 'note' => 'SECRET-ASSIGNMENT-NOTE']);

        return $id;
    }

    private function manager(): array
    {
        $user = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => ['employees.view' => true, 'operations.manage' => true]]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return [$user, $team];
    }

    private function dataQueries(): array
    {
        return collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => preg_match('/^select\b/i', trim($sql)) && (str_contains($sql, 'from "shifts"') || str_contains($sql, 'from "shift_assignments"')))->values()->all();
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Operations access must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertInstanceOf(AuthorizationException::class, $exception);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function assertHttp(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
