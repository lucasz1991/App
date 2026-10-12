<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Http\Middleware\TouchSessionPermissions;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\SessionPermissionGrant;
use App\Models\User;
use App\Models\UserAppRuntimeReceipt;
use App\Services\Personnel\UserAppRuntimeReceiptService;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Privacy\SessionActivity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class UserAppRuntimeReceiptTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private UserAppRuntimeReceiptService $service;

    private SessionPermissionGrant $grant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php'))->up();
        (require database_path('migrations/2026_10_11_220000_create_user_app_runtime_receipts.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T12:00:00Z'));
        User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($this->employee);
        request()->setLaravelSession(app('session')->driver());
        request()->session()->start();
        $this->grant = app(SessionPermissionService::class)->touch($this->employee);
        request()->session()->save();
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), request()->session()->getId());
        $this->service = app(UserAppRuntimeReceiptService::class);
    }

    public function test_receipt_records_only_coarse_browser_facts_without_purpose_consent_or_managed_device(): void
    {
        $this->assertNull($this->grant->push_decision);
        $this->assertNull($this->grant->location_decision);
        $receipt = $this->service->record($this->employee, $this->payload());
        $this->assertSame($this->employee->id, $receipt->user_id);
        $this->assertSame($this->grant->id, $receipt->session_permission_grant_id);
        $this->assertSame('android', $receipt->platform);
        $this->assertSame('standalone', $receipt->display_mode);
        $this->assertSame('2026-10-11T12:00:00.000000Z', $receipt->received_at->toISOString());
        $this->assertNull($this->grant->fresh()->push_decision);
        $this->assertNull($this->grant->fresh()->location_decision);
        $this->assertFalse(Schema::hasTable('devices'));
        $this->assertFalse(Schema::hasTable('employee_location_points'));
        foreach (['id', 'user_id', 'session_permission_grant_id'] as $key) {
            $this->assertArrayNotHasKey($key, $receipt->toArray());
        }
        $this->assertArrayNotHasKey('session_permission_grant', $receipt->load('sessionPermissionGrant')->toArray());
    }

    public function test_repeated_report_replaces_one_current_session_receipt_and_never_renews_login_grant(): void
    {
        $first = $this->service->record($this->employee, $this->payload());
        $before = $this->grant->fresh()->getRawOriginal();
        $this->travel(5)->minutes();
        $second = $this->service->record($this->employee, $this->payload(['notification_permission' => 'denied', 'display_mode' => 'browser']));
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('user_app_runtime_receipts', 1);
        $this->assertSame('denied', $second->notification_permission);
        $this->assertSame('browser', $second->display_mode);
        $this->assertSame(now()->utc()->toISOString(), $second->received_at->toISOString());
        $this->assertSame($before, $this->grant->fresh()->getRawOriginal());
    }

    public function test_strict_payload_rejects_identifiers_coordinates_user_agent_tokens_and_unrecognized_enums(): void
    {
        foreach (['user_id', 'device_assignment_id', 'grant_id', 'latitude', 'longitude', 'user_agent', 'token'] as $field) {
            try {
                $this->service->record($this->employee, $this->payload() + [$field => 'PRIVATE']);
                $this->fail('Unexpected payload field was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('user_app_runtime_receipts', 0);
            }
        }
        foreach (['platform', 'display_mode', 'notification_permission', 'geolocation_permission'] as $field) {
            try {
                $this->service->record($this->employee, $this->payload([$field => 'invented']));
                $this->fail('Unexpected runtime enum was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('user_app_runtime_receipts', 0);
            }
        }
    }

    public function test_default_prompt_unsupported_and_unknown_are_distinct_valid_observations(): void
    {
        foreach ([['notification_permission' => 'default', 'geolocation_permission' => 'prompt'],
            ['notification_permission' => 'unsupported', 'geolocation_permission' => 'unsupported'],
            ['notification_permission' => 'unknown', 'geolocation_permission' => 'unknown']] as $input) {
            $receipt = $this->service->record($this->employee, $this->payload($input));
            $this->assertSame($input['notification_permission'], $receipt->notification_permission);
            $this->assertSame($input['geolocation_permission'], $receipt->geolocation_permission);
        }
    }

    public function test_other_account_and_expired_or_ended_current_session_cannot_write(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->assertDenied(fn () => $this->service->record($other, $this->payload()));
        $this->grant->forceFill(['ended_at' => now()->utc()])->save();
        $this->assertDenied(fn () => $this->service->record($this->employee, $this->payload()));
        $this->grant->forceFill(['ended_at' => null, 'expires_at' => now()->utc()])->save();
        $this->assertDenied(fn () => $this->service->record($this->employee, $this->payload()));
        $this->assertDatabaseCount('user_app_runtime_receipts', 0);
    }

    public function test_inactive_owner_cannot_record_or_read_even_if_a_hydrated_actor_is_stale(): void
    {
        $this->service->record($this->employee, $this->payload());
        DB::table('users')->where('id', $this->employee->id)->update(['status' => false]);
        $this->assertDenied(fn () => $this->service->record($this->employee, $this->payload()));
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
    }

    public function test_reader_isolated_by_employee_prefers_mobile_and_retains_stale_timestamp(): void
    {
        $mobile = $this->service->record($this->employee, $this->payload());
        $this->travel(16)->minutes();
        $desktop = $this->syntheticReceipt($this->employee, ['platform' => 'desktop']);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->syntheticReceipt($other);
        $this->assertSame($mobile->id, $this->service->latestMobileForUser($this->employee->id)->id);
        $this->assertSame($mobile->id, $this->service->latestForUser($this->employee->id)->id);
        $this->assertFalse($this->service->isFresh($mobile));
        $mobile->delete();
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        $this->assertSame($desktop->id, $this->service->latestForUser($this->employee->id)->id);
    }

    public function test_exact_fifteen_minute_boundary_is_fresh_and_future_or_seven_day_old_data_is_excluded(): void
    {
        $receipt = $this->service->record($this->employee, $this->payload());
        $this->travel(15)->minutes();
        $this->assertTrue($this->service->isFresh($receipt));
        $this->travel(1)->seconds();
        $this->assertFalse($this->service->isFresh($receipt));
        $receipt->forceFill(['received_at' => now()->utc()->addSecond()])->save();
        $this->assertFalse($this->service->isFresh($receipt));
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        $receipt->forceFill(['received_at' => now()->utc()->subDays(7)])->save();
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
    }

    public function test_reader_immediately_excludes_ended_and_expired_grants(): void
    {
        $this->service->record($this->employee, $this->payload());
        $this->grant->forceFill(['ended_at' => now()->utc()])->save();
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        $this->grant->forceFill(['ended_at' => null, 'expires_at' => now()->utc()])->save();
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
    }

    public function test_reader_checks_physical_database_session_deletion_and_expiry_without_acceptance(): void
    {
        $this->service->record($this->employee, $this->payload());
        (require database_path('migrations/2023_07_04_121951_create_sessions_table.php'))->up();
        config(['session.driver' => 'database']);
        $this->app->instance('request', Request::create('/profile-read', 'GET'));
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        DB::table('sessions')->insert(['id' => $this->grant->session_identifier, 'user_id' => $this->employee->id,
            'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $this->assertNotNull($this->service->latestMobileForUser($this->employee->id));
        DB::table('sessions')->update(['last_activity' => now()->subHours(72)->getTimestamp()]);
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        DB::table('sessions')->delete();
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
    }

    public function test_reader_never_queries_push_native_gps_settings_or_hardware(): void
    {
        $this->service->record($this->employee, $this->payload());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNotNull($this->service->latestMobileForUser($this->employee->id));
        $queries = implode('\n', array_column(DB::getQueryLog(), 'query'));
        $this->assertDoesNotMatchRegularExpression('/(?:from|join)\s+["`]*(?:push_subscriptions|devices|device_assignments|mobile_location_installations|employee_location_points|employee_location_consents|settings)["`]*/i', $queries);
        $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|alter|create|drop)\b/i', $queries);
    }

    public function test_missing_or_partial_runtime_schema_is_neutral_and_recording_fails_without_writes(): void
    {
        Schema::drop('user_app_runtime_receipts');
        $this->assertFalse($this->service->ready());
        $this->assertNull($this->service->latestMobileForUser($this->employee->id));
        $this->assertSame(0, $this->service->purge());
        $this->assertDenied(fn () => $this->service->record($this->employee, $this->payload()), 503);
        Schema::create('user_app_runtime_receipts', fn (Blueprint $table) => $table->id());
        $this->assertFalse($this->service->ready());
        $this->assertNull($this->service->latestForUser($this->employee->id));
        $this->assertSame(0, $this->service->purge());
    }

    public function test_retention_removes_exactly_expired_receipts_and_keeps_recent_observations(): void
    {
        $old = $this->service->record($this->employee, $this->payload());
        $old->forceFill(['received_at' => now()->utc()->subDays(7)])->save();
        $recent = $this->syntheticReceipt($this->employee);
        $this->assertSame(1, $this->service->purge());
        $this->assertDatabaseMissing('user_app_runtime_receipts', ['id' => $old->id]);
        $this->assertDatabaseHas('user_app_runtime_receipts', ['id' => $recent->id]);
    }

    public function test_endpoint_accepts_only_own_authenticated_json_and_never_exposes_identifiers(): void
    {
        $before = $this->grant->fresh()->getRawOriginal();
        $response = $this->postJson('/pwa/runtime-status', $this->payload())
            ->assertOk()->assertExactJson(['recorded' => true, 'received_at' => now()->utc()->toISOString()]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame($before, $this->grant->fresh()->getRawOriginal());
        $route = app('router')->getRoutes()->getByName('app.runtime-status');
        $this->assertContains(LogActivity::class, $route->excludedMiddleware());
        $this->assertContains(TouchSessionPermissions::class, $route->excludedMiddleware());
    }

    public function test_endpoint_rejects_anonymous_requests_and_missing_csrf_and_oversized_or_non_json_payload(): void
    {
        $this->post('/pwa/runtime-status', $this->payload())->assertStatus(415);
        $this->postJson('/pwa/runtime-status', $this->payload() + ['extra' => str_repeat('x', 2200)])->assertStatus(413);
        $this->app->bind(VerifyCsrfToken::class, RuntimeReceiptCsrfMiddleware::class);
        $this->postJson('/pwa/runtime-status', $this->payload())->assertStatus(419);
        auth('web')->forgetUser();
        $this->browserLogoutCookie();
        $this->app->bind(VerifyCsrfToken::class, VerifyCsrfToken::class);
        $this->postJson('/pwa/runtime-status', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('user_app_runtime_receipts', 0);
    }

    public function test_runtime_route_never_counts_as_session_activity_even_with_html_accept(): void
    {
        $request = Request::create('/pwa/runtime-status', 'POST', [], [], [], ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/json'], json_encode($this->payload()));
        $this->assertFalse(SessionActivity::isInteraction($request));
    }

    public function test_reader_continues_past_a_full_batch_of_deleted_sessions_to_the_latest_valid_mobile_receipt(): void
    {
        (require database_path('migrations/2023_07_04_121951_create_sessions_table.php'))->up();
        $valid = $this->service->record($this->employee, $this->payload());
        DB::table('sessions')->insert(['id' => $this->grant->session_identifier, 'user_id' => $this->employee->id,
            'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $this->travel(1)->seconds();
        for ($index = 0; $index < 25; $index++) {
            $this->syntheticReceipt($this->employee);
        }
        config(['session.driver' => 'database']);
        $this->app->instance('request', Request::create('/profile-read', 'GET'));
        $this->assertSame($valid->id, $this->service->latestMobileForUser($this->employee->id)->id);
    }

    public function test_receipt_uses_fractional_utc_under_berlin_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11T21:59:30.123456Z'));
        $receipt = $this->service->record($this->employee, $this->payload());
        $this->assertSame('2026-10-11 21:59:30.123456', DB::table('user_app_runtime_receipts')->value('received_at'));
        $this->assertSame('2026-10-11T21:59:30.123456Z', $receipt->received_at->toISOString());
    }

    public function test_migration_requires_existing_session_grants_and_is_reversible_without_touching_them(): void
    {
        $migration = require database_path('migrations/2026_10_11_220000_create_user_app_runtime_receipts.php');
        $before = $this->grant->fresh()->getRawOriginal();
        $migration->down();
        $this->assertFalse(Schema::hasTable('user_app_runtime_receipts'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('user_app_runtime_receipts', 'session_permission_grant_id'));
        $this->assertSame($before, $this->grant->fresh()->getRawOriginal());
        $migration->down();
        DB::table('session_permission_grants')->delete();
        Schema::drop('push_subscriptions');
        Schema::drop('session_permission_grants');
        try {
            $migration->up();
            $this->fail('Missing grant schema must not produce a partial receipt table.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Sitzungsfreigaben', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable('user_app_runtime_receipts'));
    }

    public function test_receipt_expiry_cleanup_is_registered_hourly_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => $event->description === 'app-runtime:purge');
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['platform' => 'android', 'display_mode' => 'standalone', 'notification_permission' => 'granted', 'geolocation_permission' => 'granted'], $overrides);
    }

    private function syntheticReceipt(User $user, array $overrides = []): UserAppRuntimeReceipt
    {
        $grant = SessionPermissionGrant::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'session_hash' => hash('sha256', random_bytes(20)),
            'session_identifier' => Str::random(40), 'last_activity_at' => now()->utc(), 'expires_at' => now()->utc()->addHours(72)]);

        return UserAppRuntimeReceipt::create($this->payload($overrides) + ['user_id' => $user->id, 'session_permission_grant_id' => $grant->id, 'received_at' => now()->utc()]);
    }

    private function assertDenied(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Runtime status operation must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function browserLogoutCookie(): void
    {
        app('session')->flush();
        app('session')->save();
    }
}

class RuntimeReceiptCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
