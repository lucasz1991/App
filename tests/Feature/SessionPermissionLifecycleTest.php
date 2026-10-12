<?php

namespace Tests\Feature;

use App\Http\Middleware\TouchSessionPermissions;
use App\Models\EmployeeLocationConsent;
use App\Models\User;
use App\Services\EmployeeLocation\EmployeeLocationService;
use App\Services\Privacy\SessionPermissionService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class SessionPermissionLifecycleTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private SessionPermissionService $permissions;

    private Store $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_08_17_070000_create_device_management_tables.php',
            '2026_10_11_120200_create_employee_location_tables.php', '2026_10_11_200000_create_session_permission_grants.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        config(['session.employee_lifetime' => 4320, 'session.lifetime' => 120, 'session.ordinary_lifetime' => 120, 'webpush.enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:00:00Z'));
        $administrator = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        EmployeeLocationSettings::save(['enabled' => true], $administrator);
        $this->permissions = app(SessionPermissionService::class);
        $this->actingAs($this->employee);
        $this->browser = new Store('test-session', new ArraySessionHandler(4320));
        $this->browser->start();
        request()->setLaravelSession($this->browser);
        $this->permissions->touch($this->employee);
    }

    public function test_new_session_has_no_preselected_permissions_and_decisions_are_independent(): void
    {
        $snapshot = $this->permissions->snapshot($this->employee);
        $this->assertTrue($snapshot['needs_decision']);
        $this->assertNull($snapshot['push_decision']);
        $this->assertNull($snapshot['location_decision']);
        $this->permissions->decide($this->employee, 'push', true);
        $this->permissions->decide($this->employee, 'location', false, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $snapshot = $this->permissions->snapshot($this->employee);
        $this->assertFalse($snapshot['needs_decision']);
        $this->assertSame('accepted', $snapshot['push_decision']);
        $this->assertSame('declined', $snapshot['location_decision']);
        $this->permissions->touch($this->employee);
        $this->assertFalse($this->permissions->snapshot($this->employee)['needs_decision']);
    }

    public function test_location_can_be_accepted_before_a_phone_is_paired_without_starting_recording(): void
    {
        $grant = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $consent = EmployeeLocationConsent::firstOrFail();
        $this->assertSame($grant->id, $consent->session_permission_grant_id);
        $this->assertNull($consent->installation_id);
        $this->assertSame('unpaired', app(EmployeeLocationService::class)->status($this->employee)['state']);
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertDatabaseCount('mobile_location_installations', 0);
    }

    public function test_midnight_and_daily_app_use_preserve_the_same_explicit_decisions(): void
    {
        $grant = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $this->permissions->decide($this->employee, 'push', false);
        $accepted = EmployeeLocationConsent::first()->accepted_at;
        $this->travel(1)->days();
        $this->assertTrue($this->permissions->active($grant, 'location'));
        $renewed = $this->permissions->touch($this->employee);
        $this->assertSame($grant->id, $renewed->id);
        $this->assertSame(now()->utc()->addHours(72)->toISOString(), $renewed->expires_at->toISOString());
        $this->assertTrue(EmployeeLocationConsent::first()->accepted_at->equalTo($accepted));
        $this->assertSame($renewed->expires_at->toISOString(), EmployeeLocationConsent::first()->expires_at->toISOString());
        $this->assertFalse($this->permissions->snapshot($this->employee)['needs_decision']);
    }

    public function test_expired_session_cannot_revive_old_decisions_even_with_the_same_session_identifier(): void
    {
        $old = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $this->permissions->decide($this->employee, 'push', true);
        $this->travel(72)->hours();
        $this->assertNull($this->permissions->current($this->employee));
        $this->assertFalse($this->permissions->active($old, 'location'));
        $fresh = $this->permissions->touch($this->employee);
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertNull($fresh->location_decision);
        $this->assertNull($fresh->push_decision);
        $this->assertNotNull($old->fresh()->ended_at);
        $this->assertNull($old->fresh()->session_identifier);
    }

    public function test_logout_ends_grants_and_new_login_needs_a_new_decision(): void
    {
        $old = $this->permissions->decide($this->employee, 'push', true);
        Event::dispatch(new Logout('web', $this->employee));
        $this->assertNull($this->permissions->current($this->employee));
        $this->assertFalse($this->permissions->active($old, 'push'));
        $this->assertNull($old->fresh()->session_identifier);
        $this->browser->migrate(true);
        $fresh = $this->permissions->touch($this->employee);
        $this->assertNull($fresh->push_decision);
        $this->assertNotSame($old->id, $fresh->id);
    }

    public function test_two_first_requests_with_empty_session_copies_reuse_one_unexpired_grant(): void
    {
        $first = $this->permissions->current($this->employee);
        $copy = new Store('test-session', new ArraySessionHandler(4320), $this->browser->getId());
        $copy->start();
        request()->setLaravelSession($copy);
        $second = $this->permissions->touch($this->employee);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('session_permission_grants', 1);
        $this->assertNull($second->push_decision);
    }

    public function test_session_rotation_does_not_transfer_the_previous_decisions(): void
    {
        $old = $this->permissions->decide($this->employee, 'push', true);
        $this->browser->migrate(true);
        $fresh = $this->permissions->touch($this->employee);
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertNull($fresh->push_decision);
        $this->assertFalse($this->permissions->active($old, 'push'));
    }

    public function test_browser_credentials_are_encrypted_and_never_returned_in_snapshot_or_model_serialization(): void
    {
        $grant = $this->permissions->current($this->employee);
        $raw = DB::table('session_permission_grants')->where('id', $grant->id)->value('session_identifier');
        $this->assertStringNotContainsString($this->browser->getId(), $raw);
        foreach (['uuid', 'session_hash', 'session_identifier'] as $key) {
            $this->assertArrayNotHasKey($key, $grant->toArray());
            $this->assertArrayNotHasKey($key, $this->permissions->snapshot($this->employee));
        }
    }

    public function test_stale_loaded_grant_cannot_bypass_a_new_revocation(): void
    {
        $stale = $this->permissions->decide($this->employee, 'push', true);
        $this->permissions->revoke($this->employee, 'push');
        $this->assertFalse($this->permissions->active($stale, 'push'));
        $this->assertSame('declined', $this->permissions->snapshot($this->employee)['push_decision']);
    }

    public function test_same_decision_retry_does_not_rewrite_acceptance_or_audit_evidence(): void
    {
        $grant = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $accepted = EmployeeLocationConsent::first()->accepted_at;
        $this->travel(5)->minutes();
        $retry = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $this->assertSame($grant->id, $retry->id);
        $this->assertTrue(EmployeeLocationConsent::first()->accepted_at->equalTo($accepted));
        $this->assertDatabaseCount('employee_location_consent_events', 1);
    }

    public function test_background_livewire_polling_never_extends_the_consent_session(): void
    {
        $original = $this->permissions->current($this->employee)->expires_at->toISOString();
        $this->travel(1)->days();
        foreach (['$refresh', 'refreshStatus', 'loadInbox', 'pollTick', 'refreshMicrosoftSync', 'refreshMessages', 'advance', 'reportRinging', 'updateWagonAssistantContext', '__dispatch'] as $method) {
            $this->livewireRequest($method);
            $this->assertSame($original, $this->permissions->current($this->employee)->expires_at->toISOString());
        }
        $this->livewireRequest('allow');
        $this->assertSame(now()->utc()->addHours(72)->toISOString(), $this->permissions->current($this->employee)->expires_at->toISOString());
    }

    public function test_native_and_queued_reads_require_an_existing_unexpired_database_session(): void
    {
        $grant = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        (require database_path('migrations/2023_07_04_121951_create_sessions_table.php'))->up();
        config(['session.driver' => 'database']);
        $this->app->instance('request', Request::create('/api/employee-location/permit', 'POST'));
        $this->assertFalse($this->permissions->active($grant, 'location'));
        DB::table('sessions')->insert(['id' => $grant->session_identifier, 'user_id' => $this->employee->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $this->assertTrue($this->permissions->active($grant, 'location'));
        DB::table('sessions')->delete();
        $this->assertFalse($this->permissions->active($grant, 'location'));
    }

    public function test_background_tile_json_and_binary_requests_do_not_extend_permission_but_document_navigation_does(): void
    {
        $original = $this->permissions->current($this->employee)->expires_at->toISOString();
        $this->travel(1)->days();
        foreach ([['/routing/tiles/1/1/1', 'image/png'], ['/push/status', 'application/json'], ['/service-worker.js', '*/*']] as [$uri, $accept]) {
            $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => $accept]);
            $request->setLaravelSession($this->browser);
            $request->setUserResolver(fn () => $this->employee);
            $this->app->instance('request', $request);
            (new TouchSessionPermissions)->handle($request, fn () => response('OK'));
            $this->assertSame($original, $this->permissions->current($this->employee)->expires_at->toISOString());
        }
        $request = Request::create('/mein-arbeitstag', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $request->setLaravelSession($this->browser);
        $request->setUserResolver(fn () => $this->employee);
        $this->app->instance('request', $request);
        (new TouchSessionPermissions)->handle($request, fn () => response('OK'));
        $this->assertSame(now()->utc()->addHours(72)->toISOString(), $this->permissions->current($this->employee)->expires_at->toISOString());
    }

    public function test_expired_session_credentials_are_removed_without_deleting_consent_evidence(): void
    {
        $grant = $this->permissions->decide($this->employee, 'location', true, EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION);
        $this->travel(72)->hours();
        $this->assertSame(1, $this->permissions->purge());
        $this->assertNull($grant->fresh()->session_identifier);
        $this->assertDatabaseCount('employee_location_consents', 1);
        $this->assertDatabaseCount('employee_location_consent_events', 1);
        $this->assertFalse($this->permissions->active($grant, 'location'));
    }

    public function test_other_account_cannot_use_the_current_users_session_grant(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->assertNull($this->permissions->current($other));
        $this->expectException(HttpException::class);
        $this->permissions->decide($other, 'push', true);
    }

    private function livewireRequest(string $method): void
    {
        $request = Request::create('/livewire/update', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['components' => [['calls' => [['method' => $method]], 'updates' => []]]], JSON_THROW_ON_ERROR));
        $request->setLaravelSession($this->browser);
        $request->setUserResolver(fn () => $this->employee);
        $this->app->instance('request', $request);
        (new TouchSessionPermissions)->handle($request, fn () => response('OK'));
    }
}
