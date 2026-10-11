<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplySessionLifetime;
use App\Models\User;
use App\Support\Privacy\EmployeeDatabaseSessionHandler;
use App\Support\Privacy\SessionLifetimePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeSessionLifetimeTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2023_07_04_121951_create_sessions_table.php'))->up();
        config(['session.lifetime' => 120, 'session.ordinary_lifetime' => 120, 'session.employee_lifetime' => 4320]);
        $this->travelTo(now()->startOfDay());
    }

    private function handler(): EmployeeDatabaseSessionHandler
    {
        return new EmployeeDatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
    }

    private function insertSession(User $user, string $id, int $idleMinutes): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => base64_encode('fixture-payload'), 'last_activity' => now()->subMinutes($idleMinutes)->timestamp]);
    }

    public function test_employee_login_survives_weekend_and_expires_at_72_hours(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->insertSession($employee, 'employee-weekend', 4319);
        $this->assertSame('fixture-payload', $this->handler()->read('employee-weekend'));
        $this->travel(1)->minutes();
        $this->assertSame('', $this->handler()->read('employee-weekend'));
    }

    public function test_admin_and_non_employee_logins_keep_two_hour_timeout(): void
    {
        foreach (['admin', 'guest', 'customer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => true]);
            $this->insertSession($user, $role, 120);
            $this->assertSame('', $this->handler()->read($role));
            $this->assertSame(120, SessionLifetimePolicy::minutes($user));
        }
    }

    public function test_deactivation_or_role_change_removes_extended_lifetime(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->insertSession($employee, 'role-change', 180);
        $this->assertSame('fixture-payload', $this->handler()->read('role-change'));
        DB::table('users')->where('id', $employee->id)->update(['role' => 'admin']);
        $this->assertSame('', $this->handler()->read('role-change'));
        DB::table('users')->where('id', $employee->id)->update(['role' => 'staff', 'status' => false]);
        $this->assertSame('', $this->handler()->read('role-change'));
    }

    public function test_admin_garbage_collection_retains_active_employee_session(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->insertSession($employee, 'active', 3000);
        $this->insertSession($employee, 'expired', 4320);
        $this->assertSame(1, $this->handler()->gc(120 * 60));
        $this->assertTrue(DB::table('sessions')->where('id', 'active')->exists());
    }

    public function test_response_cookie_lifetime_reflects_login_identity_without_leaking_to_admin(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $request = Request::create('/login', 'POST');
        $request->setUserResolver(fn () => null);
        (new ApplySessionLifetime)->handle($request, function ($request) use ($employee) {
            $this->assertSame(120, config('session.lifetime'));
            $request->setUserResolver(fn () => $employee);

            return response('logged-in');
        });
        $this->assertSame(4320, config('session.lifetime'));
        $request = Request::create('/administrator');
        $request->setUserResolver(fn () => $admin);
        (new ApplySessionLifetime)->handle($request, fn () => response('admin'));
        $this->assertSame(120, config('session.lifetime'));
        $this->assertSame(120, SessionLifetimePolicy::minutes($admin));
    }

    public function test_background_polling_does_not_renew_database_session_but_document_navigation_does(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($employee);
        $this->insertSession($employee, 'activity', 3000);
        $originalActivity = DB::table('sessions')->where('id', 'activity')->value('last_activity');
        $request = Request::create('/livewire/update', 'POST', ['components' => [['calls' => [['method' => 'refreshStatus']]]]]);
        $this->app->instance('request', $request);
        $this->handler()->write('activity', 'background-payload');
        $this->assertSame($originalActivity, DB::table('sessions')->where('id', 'activity')->value('last_activity'));
        $request = Request::create('/mein-arbeitstag', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $this->app->instance('request', $request);
        $this->handler()->write('activity', 'navigation-payload');
        $this->assertSame(now()->timestamp, DB::table('sessions')->where('id', 'activity')->value('last_activity'));
    }
}
