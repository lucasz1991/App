<?php

namespace Tests\Feature;

use App\Models\CustomerPortalIdentity;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\PersonnelResponsibility;
use App\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Personnel\EmployeePhoneStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeePhoneStatusTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_08_17_070000_create_device_management_tables.php'))->up();
        Schema::create('personnel_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('responsible_user_id');
            $table->unsignedBigInteger('user_id');
            $table->json('abilities');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    public function test_authorized_empty_profile_has_honest_main_phone_and_permission_unknown_states(): void
    {
        $view = $this->read();
        $this->assertNotNull($view);
        $this->assertSame('unset', $view['primary_state']);
        $this->assertSame('Haupthandy nicht festgelegt', $view['device_label']);
        $this->assertSame(0, $view['assigned_phone_count']);
        $this->assertSame(['installation', 'push', 'location'], array_keys($view['rows']));
        foreach ($view['rows'] as $row) {
            $this->assertSame(['label', 'icon', 'state', 'value'], array_keys($row));
            $this->assertSame('unknown', $row['state']);
            $this->assertSame('Nicht gemeldet', $row['value']);
        }
        $this->assertStringContainsString('nicht hinterlegt', $view['evidence_note']);
        Http::assertNothingSent();
    }

    public function test_one_or_many_assigned_phones_never_imply_a_primary_or_installation(): void
    {
        $phone = $this->phone();
        $one = $this->read();
        $this->assertSame(1, $one['assigned_phone_count']);
        $this->assertSame('unset', $one['primary_state']);
        $this->assertSame('unknown', $one['rows']['installation']['state']);
        $this->phone();
        DeviceAssignment::create(['device_id' => $phone->id, 'user_id' => $this->employee->id,
            'status' => 'active', 'assigned_at' => now()->subMinute()]);
        $many = $this->read();
        $this->assertSame(2, $many['assigned_phone_count']);
        $this->assertSame('unset', $many['primary_state']);
        $this->assertSame($one['rows'], $many['rows']);
    }

    public function test_assignment_count_is_exact_target_active_distinct_phone_not_other_mobile_hardware(): void
    {
        $this->phone();
        $other = User::factory()->create(['role' => 'staff']);
        $this->phone($other);
        $this->phone(overrides: ['form_factor' => 'tablet', 'platform' => 'android']);
        $this->phone(overrides: ['form_factor' => 'laptop']);
        $this->phone(overrides: ['form_factor' => 'unknown', 'platform' => 'ios']);
        $this->phone(assignment: ['status' => 'returned', 'returned_at' => now()]);
        $this->phone(assignment: ['status' => 'active', 'returned_at' => now()]);
        $this->phone(assignment: ['assigned_at' => now()->addMinute()]);
        $this->phone(overrides: ['lifecycle_status' => 'retired']);
        $deleted = $this->phone();
        $deleted->delete();
        $this->assertSame(1, $this->read()['assigned_phone_count']);
        $this->assertSame(1, $this->read($other)['assigned_phone_count']);
    }

    public function test_stale_admin_status_and_role_are_reloaded_before_any_device_query(): void
    {
        $this->phone();
        $this->assertNotNull($this->read());
        DB::table('users')->where('id', $this->admin->id)->update(['status' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue($this->admin->status);
        $this->assertNull($this->read());
        $this->assertNoSensitiveQueries();
        DB::table('users')->where('id', $this->admin->id)->update(['status' => true, 'role' => 'staff']);
        DB::flushQueryLog();
        $this->assertSame('admin', $this->admin->role);
        $this->assertNull($this->read());
        $this->assertNoSensitiveQueries();
    }

    public function test_deleted_stale_actor_and_missing_or_non_employee_target_return_no_payload(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->assertNull($this->read($otherAdmin));
        $this->assertNull(app(EmployeePhoneStatusService::class)->read(999999, $this->admin));
        DB::table('users')->where('id', $this->employee->id)->update(['role' => 'guest']);
        $this->assertNull($this->read());
        DB::table('users')->where('id', $this->admin->id)->delete();
        $this->assertNull($this->read());
    }

    public function test_unauthenticated_and_ordinary_employee_with_forged_team_grants_do_not_get_status(): void
    {
        $this->assertNull(app(EmployeePhoneStatusService::class)->read($this->employee->id));
        $ordinary = $this->viewer(teamName: 'Mitarbeiter');
        $this->assertNull($this->read(actor: $ordinary));
        $this->assertNull(app(EmployeePhoneStatusService::class)->read($ordinary->id, $ordinary));
    }

    public function test_customer_principal_never_inherits_an_internal_admin_with_the_same_numeric_id(): void
    {
        $portal = new CustomerPortalIdentity;
        $portal->forceFill(['id' => $this->admin->id, 'role' => 'admin', 'status' => true]);
        auth()->setUser($portal);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull(app(EmployeePhoneStatusService::class)->read($this->employee->id));
        $this->assertSame([], DB::getQueryLog());
    }

    public static function missingAbilities(): array
    {
        return ['employee read' => ['employees.view'], 'device read' => ['devices.view'],
            'master-data scope read' => ['employees.master-data.view']];
    }

    #[DataProvider('missingAbilities')]
    public function test_each_required_viewer_permission_is_checked_before_device_reads(string $missing): void
    {
        $abilities = array_diff(['employees.view', 'devices.view', 'employees.master-data.view'], [$missing]);
        $actor = $this->viewer(abilities: $abilities);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($this->read(actor: $actor));
        $this->assertNoSensitiveQueries();
    }

    public function test_fresh_team_permission_revocation_and_management_audience_removal_take_effect(): void
    {
        $actor = $this->viewer();
        $actor->load('currentTeam');
        $this->assertNotNull($this->read(actor: $actor));
        DB::table('teams')->where('id', $actor->current_team_id)->update(['rbac_permissions' => json_encode([
            'employees.view' => true, 'devices.view' => false, 'employees.master-data.view' => true,
        ])]);
        $this->assertTrue($actor->currentTeam->rbac_permissions['devices.view']);
        $this->assertNull($this->read(actor: $actor));
        DB::table('teams')->where('id', $actor->current_team_id)->update(['name' => 'Mitarbeiter',
            'rbac_permissions' => json_encode(array_fill_keys(['employees.view', 'devices.view', 'employees.master-data.view'], true))]);
        $this->assertNull($this->read(actor: $actor));
    }

    public function test_personnel_responsibility_restricts_targets_and_honors_fresh_dates_and_abilities(): void
    {
        $actor = $this->viewer();
        $other = User::factory()->create(['role' => 'staff']);
        $this->phone();
        $this->phone($other);
        $responsibility = PersonnelResponsibility::create(['responsible_user_id' => $actor->id,
            'user_id' => $this->employee->id, 'abilities' => ['employees.master-data.view'],
            'starts_on' => '2026-10-11', 'ends_on' => '2026-10-11', 'created_by' => $this->admin->id]);
        $this->assertSame(1, $this->read(actor: $actor)['assigned_phone_count']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($this->read($other, $actor));
        $this->assertNoSensitiveQueries();
        $responsibility->update(['ends_on' => '2026-10-10']);
        $this->assertNull($this->read(actor: $actor));
        $responsibility->update(['ends_on' => null, 'starts_on' => '2026-10-12']);
        $this->assertNull($this->read(actor: $actor));
        $responsibility->update(['starts_on' => '2026-10-11', 'abilities' => ['employees.master-data.edit']]);
        $this->assertNull($this->read(actor: $actor));
    }

    public function test_superadmin_keeps_existing_access_without_changing_any_roles_or_location_grant(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin', 'status' => true]);
        $this->phone();
        $view = $this->read(actor: $superadmin);
        $this->assertSame(1, $view['assigned_phone_count']);
        $this->assertSame('unknown', $view['rows']['location']['state']);
        $this->assertSame('superadmin', $superadmin->fresh()->role);
        $this->assertFalse($superadmin->can('operations.locations.view'));
    }

    public function test_missing_device_tables_produce_unknown_count_not_an_empty_or_missing_installation_claim(): void
    {
        Schema::drop('device_assignments');
        $view = $this->read();
        $this->assertNull($view['assigned_phone_count']);
        $this->assertSame('unset', $view['primary_state']);
        $this->assertSame('unknown', $view['rows']['installation']['state']);
    }

    public function test_partial_device_schema_is_neutral_and_never_attempts_a_broken_count_query(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropIndex('devices_form_factor_index'));
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('form_factor'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($this->read()['assigned_phone_count']);
        $this->assertNoSensitiveQueries();
    }

    public function test_existing_mobile_push_subscription_and_native_location_registration_are_not_main_phone_receipts(): void
    {
        $phone = $this->phone(overrides: ['metadata' => ['standalone' => true, 'notification_permission' => 'granted',
            'location_permission' => 'granted', 'is_primary' => true, 'secret' => 'PRIVATE-METADATA'],
            'primary_provider' => 'simulation', 'primary_provider_device_id' => 'PRIVATE-PROVIDER-ID']);
        $assignment = $phone->assignments()->first();
        $this->employee->updatePushSubscription('https://push.example.invalid/PRIVATE-ENDPOINT', 'PRIVATE-KEY', 'PRIVATE-TOKEN')
            ->forceFill(['device_name' => 'PRIVATE-PHONE', 'platform' => 'android', 'last_seen_at' => now()])->save();
        Schema::create('work_time_entries', function (Blueprint $table): void {
            $table->id();
        });
        (require database_path('migrations/2026_10_11_120200_create_employee_location_tables.php'))->up();
        DB::table('mobile_location_installations')->insert(['uuid' => fake()->uuid(), 'user_id' => $this->employee->id,
            'device_assignment_id' => $assignment->id, 'platform' => 'android', 'token_hash' => str_repeat('a', 64),
            'last_seen_at' => now(), 'quality_status' => 'valid']);
        $before = DB::table('mobile_location_installations')->get()->toJson();
        $pushBefore = PushSubscription::query()->get()->toJson();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = $this->read();
        $queries = implode('\n', array_column(DB::getQueryLog(), 'query'));
        $this->assertSame('unset', $view['primary_state']);
        $this->assertSame(1, $view['assigned_phone_count']);
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($view['rows'], 'state'));
        foreach (['push_subscriptions', 'mobile_location_installations', 'employee_location_consents',
            'employee_location_points', 'device_readiness_checks', 'settings'] as $table) {
            $this->assertStringNotContainsString($table, $queries);
        }
        $serialized = json_encode($view, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-', 'token_hash', 'endpoint', 'user_agent', 'latitude', 'longitude', 'quality_status',
            'is_primary', 'device_id', 'installation_id', 'consent_id', 'permit_id'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|alter|create|drop)\b/i', $queries);
        $this->assertSame($before, DB::table('mobile_location_installations')->get()->toJson());
        $this->assertSame($pushBefore, PushSubscription::query()->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_browser_local_report_is_not_read_from_the_viewing_admin_device(): void
    {
        $this->actingAs($this->admin);
        $this->app['request']->headers->set('User-Agent', 'PRIVATE-ADMIN-BROWSER');
        $this->app['request']->headers->set('X-PWA-Standalone', 'true');
        $this->app['request']->headers->set('X-Notification-Permission', 'granted');
        $this->app['request']->headers->set('X-Location-Permission', 'granted');
        $view = app(EmployeePhoneStatusService::class)->read($this->employee->id);
        $this->assertSame('unset', $view['primary_state']);
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($view['rows'], 'state'));
        $this->assertStringNotContainsString('PRIVATE-ADMIN-BROWSER', json_encode($view));
    }

    public function test_repeated_read_is_request_fresh_and_performs_one_aggregate_not_hardware_hydration(): void
    {
        $service = app(EmployeePhoneStatusService::class);
        $this->phone();
        $this->assertSame(1, $service->read($this->employee->id, $this->admin)['assigned_phone_count']);
        $this->phone();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(2, $service->read($this->employee->id, $this->admin)['assigned_phone_count']);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($query) => str_contains($query, 'join "devices"'));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(distinct', strtolower($queries->first()));
        $this->assertStringNotContainsString('metadata', $queries->first());
    }

    private function read(?User $employee = null, ?User $actor = null): ?array
    {
        return app(EmployeePhoneStatusService::class)->read(($employee ?? $this->employee)->id, $actor ?? $this->admin);
    }

    private function phone(?User $employee = null, array $overrides = [], array $assignment = []): Device
    {
        $phone = Device::create(array_replace(['display_name' => 'Synthetic handset', 'form_factor' => 'phone',
            'platform' => 'android', 'lifecycle_status' => 'assigned'], $overrides));
        DeviceAssignment::create(array_replace(['device_id' => $phone->id, 'user_id' => ($employee ?? $this->employee)->id,
            'status' => 'active', 'assigned_at' => now()->subHour()], $assignment));

        return $phone;
    }

    private function viewer(array $abilities = ['employees.view', 'devices.view', 'employees.master-data.view'], string $teamName = 'Verwaltung'): User
    {
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => $teamName, 'personal_team' => false,
            'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $actor->id])->save();
        $actor->forceFill(['current_team_id' => $team->id])->save();

        return $actor;
    }

    private function assertNoSensitiveQueries(): void
    {
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|join)\s+["`]*(?:devices|device_assignments|push_subscriptions|mobile_location_installations|employee_location_consents|employee_location_points)["`]*/i', $query['query']);
        }
    }
}
