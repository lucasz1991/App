<?php

namespace Tests\Feature;

use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RoleMiddleware;
use App\Models\CustomerPortalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class UserSuperAdminRoleRecognitionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        $compiledViews = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railtime-superadmin-role-tests-'.Str::uuid();
        File::ensureDirectoryExists($compiledViews);
        config(['view.compiled' => $compiledViews, 'cache.default' => 'array']);
    }

    public function test_an_explicit_superadmin_is_recognized_without_the_primary_user_id(): void
    {
        $user = $this->user('superadmin', id: 45);

        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->isActive());
        $this->assertSame('admin', $user->dashboardAudience());
        $this->assertTrue($user->usesAdminLayout());
        $this->assertTrue($user->canViewManagementDashboard());
        $this->assertSame('superadmin', $user->role);
        $this->assertSame(45, $user->id);
        $this->assertSame([], $user->getChanges());
        $this->assertFalse($user->exists);
    }

    public function test_existing_ordinary_and_primary_administrator_contracts_are_preserved(): void
    {
        foreach ([1 => true, 45 => false] as $id => $superadmin) {
            $user = $this->user('admin', id: $id);

            $this->assertTrue($user->isAdmin());
            $this->assertSame($superadmin, $user->isSuperAdmin());
            $this->assertSame('admin', $user->dashboardAudience());
            $this->assertTrue($user->usesAdminLayout());
            $this->assertSame('admin', $user->role);
        }
    }

    public function test_a_persisted_superadmin_role_is_recognized_by_read_only_checks_without_rewriting_the_account(): void
    {
        $stored = User::factory()->create(['id' => 45, 'role' => 'superadmin', 'status' => true]);
        $original = $stored->fresh()->getRawOriginal();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $user = User::findOrFail($stored->id);
            $user->setRelation('currentTeam', null);

            $this->assertTrue($user->isAdmin());
            $this->assertTrue($user->isSuperAdmin());
            $this->assertSame('admin', $user->dashboardAudience());
            $this->assertTrue($user->usesAdminLayout());
            $this->assertTrue(Gate::forUser($user)->allows('employees.view'));
            $this->assertFalse(Gate::forUser($user)->allows('operations.locations.view'));
            $this->assertSame($original, $user->getRawOriginal());
            $this->assertFalse($user->isDirty());
            $this->assertNotEmpty(DB::getQueryLog());

            foreach (DB::getQueryLog() as $query) {
                $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
            }
        } finally {
            DB::disableQueryLog();
        }

        $this->assertDatabaseHas('users', ['id' => 45, 'role' => 'superadmin', 'status' => true]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_other_roles_do_not_gain_admin_from_a_role_name_or_all_team_permissions(): void
    {
        foreach (['staff', 'customer', 'guest', 'management', 'administration', 'unknown', 'Admin', 'SUPERADMIN', 'super-admin', ' superadmin'] as $role) {
            $user = $this->user($role);
            $user->setRelation('currentTeam', $this->team('Administratoren', array_fill_keys(RbacCatalog::allPermissions(), true)));

            $this->assertFalse($user->isAdmin(), $role);
            $this->assertFalse($user->isSuperAdmin(), $role);
            $this->assertFalse($user->usesAdminLayout(), $role);
            $this->assertNotSame('admin', $user->dashboardAudience(), $role);
            $this->assertFalse(Gate::forUser($user)->allows('employees.delete'), $role);
            $this->assertFalse(Gate::forUser($user)->allows('devices.wipe'), $role);
        }
    }

    public function test_administrator_and_management_teams_do_not_become_global_administrator_roles(): void
    {
        foreach (['Administratoren', 'Administrator', 'Administration', 'Verwaltung', 'Mitarbeiter', 'Gäste'] as $name) {
            $user = $this->user('staff');
            $user->setRelation('currentTeam', $this->team($name, array_fill_keys(RbacCatalog::allPermissions(), true)));

            $this->assertFalse($user->isAdmin(), $name);
            $this->assertFalse($user->isSuperAdmin(), $name);
            $this->assertFalse($user->usesAdminLayout(), $name);
            $this->assertNotSame('admin', $user->dashboardAudience(), $name);
        }
    }

    public function test_native_home_redirect_uses_the_admin_route_for_superadmins_without_changing_roles(): void
    {
        $this->withoutMiddleware();

        foreach (['admin', 'superadmin'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('home'))->assertRedirect(route('admin.dashboard'));
            $this->assertSame($role, $user->role);
        }

        $this->actingAs($this->user('staff'))->get(route('home'))->assertRedirect(route('dashboard'));
    }

    public function test_role_admin_middleware_accepts_the_two_explicit_internal_admin_roles(): void
    {
        foreach (['admin', 'superadmin'] as $role) {
            $user = $this->user($role);
            $response = $this->roleResponse($user, ['admin']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('native-admin-route', $response->getContent());
            $this->assertSame($role, $user->role);
        }
    }

    public function test_admin_hierarchy_does_not_grant_unrequested_staff_or_other_roles(): void
    {
        foreach (['admin', 'superadmin'] as $role) {
            foreach (['staff', 'customer', 'management', 'unknown'] as $requested) {
                $response = $this->roleResponse($this->user($role), [$requested]);

                $this->assertSame(302, $response->getStatusCode());
                $this->assertSame(route('dashboard'), $response->headers->get('Location'));
            }
        }

        $this->assertSame(200, $this->roleResponse($this->user('staff'), ['staff'])->getStatusCode());
        $this->assertSame(200, $this->roleResponse($this->user('superadmin'), ['staff', 'admin'])->getStatusCode());
    }

    public function test_staff_and_privileged_team_members_cannot_enter_role_admin_routes(): void
    {
        foreach (['staff', 'management', 'administration', 'customer'] as $role) {
            $user = $this->user($role);
            $user->setRelation('currentTeam', $this->team('Administratoren', array_fill_keys(RbacCatalog::allPermissions(), true)));
            $response = $this->roleResponse($user, ['admin']);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(route('dashboard'), $response->headers->get('Location'));
            $this->assertSame($role, $user->role);
        }
    }

    public function test_portal_principals_cannot_inherit_admin_routes_even_with_forged_internal_role_values(): void
    {
        foreach (['admin', 'superadmin', 'staff'] as $role) {
            $portal = new CustomerPortalIdentity;
            $portal->forceFill(['id' => 1, 'role' => $role, 'status' => true, 'active' => true]);
            $response = $this->roleResponse($portal, [$role]);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(route('dashboard'), $response->headers->get('Location'));
            $this->assertFalse(Gate::forUser($portal)->allows('employees.view'));
            $this->assertFalse(Gate::forUser($portal)->allows('operations.locations.view'));
            $this->assertSame($role, $portal->role);
        }
    }

    public function test_guest_redirect_and_missing_requested_role_contracts_remain_unchanged(): void
    {
        Auth::forgetGuards();
        $response = app(RoleMiddleware::class)->handle($this->adminRequest(), fn () => new Response('must-not-run'), 'admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('admin.login'), $response->headers->get('Location'));

        $this->actingAs($this->user('superadmin'));
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        try {
            app(RoleMiddleware::class)->handle($this->adminRequest(), fn () => new Response('must-not-run'));
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            throw $exception;
        }
    }

    public function test_explicit_superadmins_receive_existing_admin_abilities_but_not_an_implicit_location_grant(): void
    {
        foreach (['admin', 'superadmin'] as $role) {
            $user = $this->user($role);

            foreach (array_merge(RbacCatalog::allPermissions(), ['employees.delete', 'devices.wipe', 'devices.providers.manage']) as $ability) {
                $this->assertSame($ability !== 'operations.locations.view', Gate::forUser($user)->allows($ability), $role.':'.$ability);
            }
        }
    }

    public function test_location_access_requires_the_exact_stored_true_value_even_for_an_explicit_superadmin(): void
    {
        foreach (['admin', 'superadmin'] as $role) {
            $user = $this->user($role);

            foreach ([null, false, 'true', 1, true] as $grant) {
                $permissions = $grant === null ? [] : ['operations.locations.view' => $grant];
                $user->setRelation('currentTeam', $this->team('Administratoren', $permissions));

                $this->assertSame($grant === true, Gate::forUser($user)->allows('operations.locations.view'));
                $this->assertTrue(Gate::forUser($user)->allows('employees.view'));
                $this->assertSame($role, $user->role);
            }

            $user->forceFill(['status' => false]);
            $this->assertFalse(Gate::forUser($user)->allows('operations.locations.view'));
            $this->assertFalse($user->isActive());
        }
    }

    public function test_maintenance_admin_recognition_preserves_staff_and_portal_exclusion(): void
    {
        Cache::put('maintenance_mode', (object) ['value' => true, 'updated_at' => null], 60);

        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAs($this->user($role));
            $response = app(MaintenanceMode::class)->handle(Request::create('/arbeitsplatz'), fn () => new Response('native-admin-route'));

            $this->assertSame('native-admin-route', $response->getContent());
        }

        $portal = new CustomerPortalIdentity;
        $portal->forceFill(['id' => 1, 'role' => 'superadmin', 'status' => true]);

        foreach ([$this->user('staff'), $portal] as $actor) {
            $this->actingAs($actor);
            $response = app(MaintenanceMode::class)->handle(Request::create('/arbeitsplatz'), fn () => new Response('must-not-run'));

            $this->assertNotSame('must-not-run', $response->getContent());
            $this->assertSame('errors.maintenance', $response->original->name());
        }
    }

    private function user(string $role, int $id = 45): User
    {
        $user = new User;
        $user->forceFill(['id' => $id, 'role' => $role, 'name' => 'Isolated role fixture', 'status' => true]);
        $user->setRelation('currentTeam', null);

        return $user;
    }

    private function team(string $name, array $permissions): Team
    {
        $team = new Team;
        $team->forceFill(['id' => 90, 'name' => $name, 'personal_team' => false, 'rbac_permissions' => $permissions]);

        return $team;
    }

    private function adminRequest(): Request
    {
        $request = Request::create('/administrator/settings');
        $request->setRouteResolver(fn () => (new Route('GET', 'administrator/settings', fn () => null))->name('admin.settings'));
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function roleResponse(Authenticatable $actor, array $roles): Response
    {
        $this->actingAs($actor);

        return app(RoleMiddleware::class)->handle($this->adminRequest(), fn () => new Response('native-admin-route'), ...$roles);
    }
}
