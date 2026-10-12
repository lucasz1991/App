<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Http\Middleware\TouchSessionPermissions;
use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Support\Facades\Auth;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PwaSessionStatusTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_10_11_200000_create_session_permission_grants.php'))->up();
    }

    public function test_authenticated_probe_returns_current_csrf_and_opaque_account_binding_without_touching_consent(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($user);
        request()->setLaravelSession(app('session')->driver());
        request()->session()->start();
        request()->session()->put(Auth::guard('web')->getName(), $user->id);
        $grant = app(SessionPermissionService::class)->touch($user);
        request()->session()->save();
        $this->withCredentials()->withCookie(config('session.cookie'), request()->session()->getId());
        $before = $grant->fresh()->getRawOriginal();
        $this->travel(10)->minutes();
        $response = $this->getJson('/pwa/session-status')->assertOk();
        $response->assertExactJson([
            'authenticated' => true,
            'csrf_token' => session()->token(),
            'account_binding' => WebPushConfiguration::accountBinding($user->id),
        ]);
        $this->assertNotEmpty($response->json('csrf_token'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame(now()->addHours(72)->timestamp, $response->getCookie(config('session.cookie'))->getExpiresTime());
        $this->assertSame($before, $grant->fresh()->getRawOriginal());
        $this->assertDatabaseCount('session_permission_grants', 1);
        $route = app('router')->getRoutes()->getByName('pwa.session-status');
        $this->assertContains(LogActivity::class, $route->excludedMiddleware());
        $this->assertContains(TouchSessionPermissions::class, $route->excludedMiddleware());
    }

    public function test_anonymous_probe_requires_login_and_creates_no_consent(): void
    {
        $this->getJson('/pwa/session-status')->assertUnauthorized();
        $this->assertDatabaseCount('session_permission_grants', 0);
    }

    public function test_account_switch_can_be_detected_and_probe_never_creates_consent(): void
    {
        $one = User::factory()->create(['role' => 'staff', 'status' => true]);
        $two = User::factory()->create(['role' => 'staff', 'status' => true]);
        $first = $this->actingAs($one)->withSession([Auth::guard('web')->getName() => $one->id])->getJson('/pwa/session-status')->assertOk()->json('account_binding');
        $second = $this->actingAs($two)->withSession([Auth::guard('web')->getName() => $two->id])->getJson('/pwa/session-status')->assertOk()->json('account_binding');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('session_permission_grants', 0);
    }

    public function test_remember_me_cookie_cannot_recreate_an_expired_session_during_probe(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => true, 'remember_token' => 'remember-fixture']);
        $guard = Auth::guard('web');
        $this->withCredentials()->withCookie($guard->getRecallerName(), $user->id.'|remember-fixture|'.$user->getAuthPassword());
        $this->getJson('/pwa/session-status')->assertUnauthorized();
        $this->assertNull($guard->user());
        $this->assertNull(session($guard->getName()));
        $this->assertDatabaseCount('session_permission_grants', 0);
    }

    public function test_deactivated_account_returns_a_terminal_json_response_without_redirect(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => false]);
        $response = $this->actingAs($user)->withSession([Auth::guard('web')->getName() => $user->id])
            ->getJson('/pwa/session-status')->assertForbidden();
        $this->assertFalse($response->headers->has('Location'));
        $this->assertDatabaseCount('session_permission_grants', 0);
    }
}
