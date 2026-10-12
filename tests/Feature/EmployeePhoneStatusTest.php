<?php

namespace Tests\Feature;

use App\Livewire\Profile\EmployeePhoneStatus;
use App\Models\CustomerPortalIdentity;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\PersonnelResponsibility;
use App\Models\PushSubscription;
use App\Models\SessionPermissionGrant;
use App\Models\Team;
use App\Models\User;
use App\Models\UserAppRuntimeReceipt;
use App\Services\Personnel\EmployeePhoneStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
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

    public function test_authorized_empty_profile_selects_automatically_without_inventing_permission_states(): void
    {
        $view = $this->read();
        $this->assertNotNull($view);
        $this->assertSame('automatic', $view['selection']);
        $this->assertSame('Automatische Geräteauswahl', $view['device_label']);
        $this->assertSame(0, $view['assigned_phone_count']);
        $this->assertSame(['installation', 'push', 'location'], array_keys($view['rows']));
        foreach ($view['rows'] as $row) {
            $this->assertSame(['label', 'icon', 'state', 'value'], array_keys($row));
            $this->assertSame('unknown', $row['state']);
            $this->assertSame('Nicht gemeldet', $row['value']);
        }
        $this->assertStringContainsString('noch kein Status empfangen', $view['evidence_note']);
        Http::assertNothingSent();
    }

    public function test_one_or_many_assigned_phones_never_imply_a_primary_or_installation(): void
    {
        $phone = $this->phone();
        $one = $this->read();
        $this->assertSame(1, $one['assigned_phone_count']);
        $this->assertSame('automatic', $one['selection']);
        $this->assertSame('unknown', $one['rows']['installation']['state']);
        $this->phone();
        DeviceAssignment::create(['device_id' => $phone->id, 'user_id' => $this->employee->id,
            'status' => 'active', 'assigned_at' => now()->subMinute()]);
        $many = $this->read();
        $this->assertSame(2, $many['assigned_phone_count']);
        $this->assertSame('automatic', $many['selection']);
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
        $this->assertSame('automatic', $view['selection']);
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
        $this->assertSame('automatic', $view['selection']);
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
        $this->assertSame('automatic', $view['selection']);
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

    public function test_fresh_mobile_runtime_report_supplies_actual_browser_states_without_inventing_a_phone_assignment(): void
    {
        $this->runtimeReceipt();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = $this->read();
        $this->assertSame('automatic', $view['selection']);
        $this->assertSame(0, $view['assigned_phone_count']);
        $this->assertSame('Automatisch gewählt: iPhone / iPad', $view['device_label']);
        $this->assertSame(['active', 'ready', 'ready'], array_column($view['rows'], 'state'));
        $this->assertSame('Als Web-App geöffnet', $view['rows']['installation']['value']);
        $this->assertSame('Push im Browser erlaubt', $view['rows']['push']['value']);
        $this->assertSame('Standort im Browser erlaubt', $view['rows']['location']['value']);
        $this->assertStringContainsString('11.10.2026 12:00', $view['evidence_note']);
        $this->assertStringContainsString('kein Nachweis einer laufenden Ortung', $view['rows']['location']['evidence']);
        $this->assertStringContainsString('RailTime-Sitzungsfreigabe getrennt', $view['rows']['push']['evidence']);
        $queries = implode('\n', array_column(DB::getQueryLog(), 'query'));
        foreach (['push_subscriptions', 'mobile_location_installations', 'employee_location_consents', 'employee_location_points', 'settings'] as $table) {
            $this->assertStringNotContainsString($table, $queries);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|alter|create|drop)\b/i', $queries);
        $this->assertDatabaseCount('device_assignments', 0);
        Http::assertNothingSent();
    }

    public function test_newer_automatic_desktop_report_does_not_displace_more_recent_mobile_use(): void
    {
        $this->runtimeReceipt(['platform' => 'android', 'received_at' => now()->subMinutes(5)], grant: ['last_activity_at' => now()->subMinutes(5)]);
        $this->runtimeReceipt(['platform' => 'desktop', 'display_mode' => 'browser', 'notification_permission' => 'denied', 'geolocation_permission' => 'denied'], grant: ['last_activity_at' => now()->subMinutes(10)]);
        $view = $this->read();
        $this->assertSame('Automatisch gewählt: Android-Gerät', $view['device_label']);
        $this->assertSame('Als Web-App geöffnet', $view['rows']['installation']['value']);
        $this->assertSame('Push im Browser erlaubt', $view['rows']['push']['value']);
        $this->assertStringContainsString('11.10.2026 11:55', $view['evidence_note']);
    }

    public function test_admin_or_another_employees_mobile_report_never_supplies_the_target_status(): void
    {
        $this->runtimeReceipt(user: $this->admin);
        $this->runtimeReceipt(user: User::factory()->create(['role' => 'staff', 'status' => true]));
        $view = $this->read();
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($view['rows'], 'state'));
        $this->assertSame('Automatische Geräteauswahl', $view['device_label']);
    }

    public function test_status_automatically_switches_between_phone_and_computer_with_distinct_usage_and_report_times(): void
    {
        $phone = $this->runtimeReceipt(['received_at' => now()->subMinutes(2)], grant: ['last_activity_at' => now()->subMinutes(3)]);
        $computer = $this->runtimeReceipt(['platform' => 'desktop', 'display_mode' => 'browser', 'notification_permission' => 'denied', 'geolocation_permission' => 'prompt'], grant: ['last_activity_at' => now()->subMinute()]);
        $view = $this->read();
        $this->assertSame('Automatisch gewählt: Computer', $view['device_label']);
        $this->assertSame(['info', 'denied', 'info'], array_column($view['rows'], 'state'));
        $this->assertStringContainsString('Letzte App-Nutzung: 11.10.2026 11:59 Uhr.', $view['evidence_note']);
        $this->assertStringContainsString('Letzte Statusmeldung: 11.10.2026 12:00 Uhr.', $view['evidence_note']);

        $phone->sessionPermissionGrant->forceFill(['last_activity_at' => now()])->save();
        $view = $this->read();
        $this->assertSame('Automatisch gewählt: iPhone / iPad', $view['device_label']);
        $this->assertSame(['active', 'ready', 'ready'], array_column($view['rows'], 'state'));
        $this->assertStringContainsString('Letzte App-Nutzung: 11.10.2026 12:00 Uhr.', $view['evidence_note']);
        $this->assertStringContainsString('Letzte Statusmeldung: 11.10.2026 11:58 Uhr.', $view['evidence_note']);
        $this->assertDatabaseCount('device_assignments', 0);

        $phone->sessionPermissionGrant->forceFill(['ended_at' => now()])->save();
        $this->assertSame('Automatisch gewählt: Computer', $this->read()['device_label']);
        $computer->sessionPermissionGrant->forceFill(['expires_at' => now()])->save();
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($this->read()['rows'], 'state'));
    }

    public function test_each_status_popup_has_one_report_time_and_no_primary_phone_or_inventory_setup(): void
    {
        $this->runtimeReceipt();
        $this->phone();
        $html = Livewire::actingAs($this->admin)->test(EmployeePhoneStatus::class, ['userId' => $this->employee->id, 'profilePrefix' => 'synthetic-runtime-profile'])->html();
        $this->assertSame(3, substr_count($html, 'Letzte Statusmeldung:'));
        $this->assertSame(3, substr_count($html, 'Letzte App-Nutzung:'));
        $this->assertSame(3, substr_count($html, 'Automatisch gewählt: iPhone / iPad'));
        $this->assertStringNotContainsString('Haupthandy', $html);
        $this->assertStringNotContainsString('Hauptgerät', $html);
        $this->assertStringNotContainsString('Handy zugewiesen', $html);
        $this->assertStringContainsString('kein Nachweis einer laufenden Ortung', $html);
        $this->assertStringContainsString('RailTime-Sitzungsfreigabe getrennt', $html);
    }

    public function test_old_runtime_report_is_explicitly_historical_and_does_not_show_current_ready_indicators(): void
    {
        $this->runtimeReceipt(['received_at' => now()->subMinutes(16)]);
        $view = $this->read();
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($view['rows'], 'state'));
        $this->assertSame('Zuletzt gemeldet: Als Web-App geöffnet', $view['rows']['installation']['value']);
        $this->assertStringContainsString('Letzte Statusmeldung: 11.10.2026 11:44', $view['evidence_note']);
        $this->assertStringContainsString('aktueller Gerätestatus unbekannt', $view['evidence_note']);
        $this->assertStringContainsString('Letzte App-Nutzung: 11.10.2026 12:00', $view['evidence_note']);
    }

    public function test_mobile_report_is_fresh_at_fifteen_minutes_and_becomes_neutral_immediately_afterward(): void
    {
        $this->runtimeReceipt(['received_at' => now()->subMinutes(15)]);
        $this->assertSame('active', $this->read()['rows']['installation']['state']);
        $this->travel(1)->seconds();
        $this->assertSame('unknown', $this->read()['rows']['installation']['state']);
    }

    public function test_ended_expired_future_and_expired_retention_reports_cannot_supply_the_phone_status(): void
    {
        $this->runtimeReceipt(grant: ['ended_at' => now()]);
        $this->runtimeReceipt(grant: ['expires_at' => now()]);
        $this->runtimeReceipt(['received_at' => now()->addSecond()]);
        $this->runtimeReceipt(['received_at' => now()->subDays(7)]);
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($this->read()['rows'], 'state'));
    }

    public function test_missing_physical_database_login_hides_otherwise_positive_mobile_report(): void
    {
        $receipt = $this->runtimeReceipt();
        (require database_path('migrations/2023_07_04_121951_create_sessions_table.php'))->up();
        config(['session.driver' => 'database']);
        $this->assertSame('unknown', $this->read()['rows']['installation']['state']);
        $grant = SessionPermissionGrant::findOrFail($receipt->session_permission_grant_id);
        DB::table('sessions')->insert(['id' => $grant->session_identifier, 'user_id' => $this->employee->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->assertSame('active', $this->read()['rows']['installation']['state']);
        DB::table('sessions')->delete();
        $this->assertSame('unknown', $this->read()['rows']['installation']['state']);
    }

    public function test_browser_mode_and_permission_denial_or_pending_are_reported_without_claiming_uninstallation_or_tracking(): void
    {
        $receipt = $this->runtimeReceipt(['display_mode' => 'browser', 'notification_permission' => 'denied', 'geolocation_permission' => 'prompt']);
        $view = $this->read();
        $this->assertSame('Im Browser geöffnet', $view['rows']['installation']['value']);
        $this->assertSame('info', $view['rows']['installation']['state']);
        $this->assertSame('denied', $view['rows']['push']['state']);
        $this->assertSame('info', $view['rows']['location']['state']);
        $this->assertStringNotContainsString('nicht installiert', json_encode($view));
        $receipt->forceFill(['display_mode' => 'unknown', 'notification_permission' => 'unsupported', 'geolocation_permission' => 'unknown'])->save();
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column($this->read()['rows'], 'state'));
    }

    public function test_runtime_receipt_does_not_disclose_identifiers_credentials_or_durable_client_payloads(): void
    {
        $receipt = $this->runtimeReceipt();
        $grant = SessionPermissionGrant::findOrFail($receipt->session_permission_grant_id);
        $serialized = json_encode($this->read(), JSON_THROW_ON_ERROR);
        foreach ([$grant->uuid, $grant->session_hash, $grant->session_identifier, 'session_permission_grant_id', 'latitude', 'longitude', 'token', 'endpoint'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_runtime_receipts_are_not_queried_when_current_device_permissions_are_missing(): void
    {
        $this->runtimeReceipt();
        $viewer = $this->viewer(['employees.view', 'employees.master-data.view']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($this->read(actor: $viewer));
        $this->assertNoSensitiveQueries();
    }

    public function test_runtime_phone_rail_refreshes_independently_with_native_popup_accessibility_and_fresh_revocation(): void
    {
        $receipt = $this->runtimeReceipt();
        $viewer = $this->viewer();
        $component = Livewire::actingAs($viewer)->test(EmployeePhoneStatus::class, ['userId' => $this->employee->id, 'profilePrefix' => 'synthetic-runtime-profile'])
            ->assertSuccessful()->assertSee('wire:poll.visible.60s="refreshStatus"', false)
            ->assertSee('Als Web-App geöffnet')->assertSee('Standort im Browser erlaubt')
            ->assertSee('aria-haspopup="dialog"', false)->assertSee('kein Nachweis einer laufenden Ortung');
        $receipt->forceFill(['notification_permission' => 'denied'])->save();
        $component->call('refreshStatus')->assertSee('Push im Browser blockiert');
        DB::table('teams')->where('id', $viewer->current_team_id)->update(['rbac_permissions' => json_encode(['employees.view' => true, 'devices.view' => false, 'employees.master-data.view' => true])]);
        $component->call('refreshStatus')->assertSuccessful()->assertDontSee('data-profile-phone-status', false)->assertDontSee('Push im Browser blockiert');
        $this->assertSame('denied', $receipt->fresh()->notification_permission);
    }

    public function test_phone_rail_client_cannot_switch_the_employee_target(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->test(EmployeePhoneStatus::class, ['userId' => $this->employee->id, 'profilePrefix' => 'synthetic-runtime-profile'])->set('userId', $this->admin->id);
    }

    public function test_phone_rail_cannot_continue_after_the_viewing_account_changes(): void
    {
        $this->runtimeReceipt();
        $component = Livewire::actingAs($this->admin)->test(EmployeePhoneStatus::class, ['userId' => $this->employee->id, 'profilePrefix' => 'synthetic-runtime-profile']);
        auth()->setUser(User::factory()->create(['role' => 'admin', 'status' => true]));
        $component->call('refreshStatus')->assertForbidden();
    }

    private function runtimeReceipt(array $data = [], ?User $user = null, array $grant = []): UserAppRuntimeReceipt
    {
        $user ??= $this->employee;
        if (! Schema::hasTable('session_permission_grants')) {
            (require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php'))->up();
            (require database_path('migrations/2026_10_11_220000_create_user_app_runtime_receipts.php'))->up();
        }
        $grant = SessionPermissionGrant::create(array_replace(['uuid' => fake()->uuid(), 'user_id' => $user->id,
            'session_hash' => hash('sha256', fake()->uuid()), 'session_identifier' => 'private-test-session-'.fake()->uuid(),
            'last_activity_at' => now(), 'expires_at' => now()->addHours(72)], $grant));

        return UserAppRuntimeReceipt::create(array_replace(['user_id' => $user->id, 'session_permission_grant_id' => $grant->id,
            'platform' => 'ios', 'display_mode' => 'standalone', 'notification_permission' => 'granted', 'geolocation_permission' => 'granted', 'received_at' => now()], $data));
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
            $this->assertDoesNotMatchRegularExpression('/(?:from|join)\s+["`]*(?:devices|device_assignments|push_subscriptions|mobile_location_installations|employee_location_consents|employee_location_points|user_app_runtime_receipts|session_permission_grants)["`]*/i', $query['query']);
        }
    }
}
