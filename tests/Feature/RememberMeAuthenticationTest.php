<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class RememberMeAuthenticationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const REMEMBER_LIFETIME_MINUTES = 576000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);
    }

    public function test_normal_session_lifetime_remains_unchanged(): void
    {
        $this->assertSame(120, (int) config('session.lifetime'));
        $this->assertFalse((bool) config('session.expire_on_close'));
        $this->assertSame(
            self::REMEMBER_LIFETIME_MINUTES,
            (int) config('auth.remember_lifetime'),
        );

        $sessionConfig = file_get_contents(config_path('session.php'));
        $environmentExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("env('SESSION_LIFETIME', 120)", $sessionConfig);
        $this->assertStringContainsString('SESSION_LIFETIME=120', $environmentExample);
        $this->assertStringContainsString(
            'AUTH_REMEMBER_LIFETIME='.self::REMEMBER_LIFETIME_MINUTES,
            $environmentExample,
        );
    }

    public function test_login_forms_explain_the_400_day_trusted_device_contract(): void
    {
        foreach ([route('login'), route('admin.login')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('name="remember"', escape: false)
                ->assertSee('aria-describedby="remember-help"', escape: false)
                ->assertSee(__('app.remember_me_hint'));
        }
    }

    public function test_unchecked_login_does_not_issue_a_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('dashboard'))
            ->assertCookieMissing($recallerName);

        $this->assertEmpty($user->fresh()->getRememberToken());
    }

    public function test_checked_login_issues_the_maximum_400_day_remember_cookie(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00');

        try {
            $user = User::factory()->create(['remember_token' => null]);
            $recallerName = Auth::guard('web')->getRecallerName();
            $expectedExpiry = now()->addMinutes(self::REMEMBER_LIFETIME_MINUTES)->getTimestamp();

            $response = $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
                'remember' => 'on',
            ]);

            $response
                ->assertRedirect(route('dashboard'))
                ->assertCookie($recallerName)
                ->assertCookieNotExpired($recallerName);

            $cookie = $response->getCookie($recallerName, false);

            $this->assertNotNull($cookie);
            $this->assertSame($expectedExpiry, $cookie->getExpiresTime());
            $this->assertNotEmpty($user->fresh()->getRememberToken());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_remember_cookie_reauthenticates_after_the_normal_session_is_gone(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $guard = Auth::guard('web');
        $recallerName = $guard->getRecallerName();
        $loginResponse = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);
        $recaller = $loginResponse->getCookie($recallerName)?->getValue();

        $this->assertNotNull($recaller);

        session()->flush();
        session()->regenerate(true);
        Auth::forgetGuards();

        $this->withCookie($recallerName, $recaller)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh(), 'web');
        $this->assertTrue(Auth::guard('web')->viaRemember());
    }

    public function test_admin_login_uses_the_same_remember_contract(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'remember_token' => null,
        ]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $this->withSession(['url.intended' => route('admin.dashboard')])
            ->post(route('login.store'), [
                'email' => $admin->email,
                'password' => 'password',
                'admin_login' => '1',
                'remember' => 'on',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertCookie($recallerName)
            ->assertCookieNotExpired($recallerName);

        $this->assertNotEmpty($admin->fresh()->getRememberToken());
    }

    public function test_logout_revokes_the_long_lived_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $recallerName = Auth::guard('web')->getRecallerName();
        $loginResponse = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);
        $recaller = $loginResponse->getCookie($recallerName)?->getValue();
        $rememberToken = $user->fresh()->getRememberToken();

        $this->assertNotNull($recaller);
        $this->assertNotEmpty($rememberToken);

        $this->withCookie($recallerName, $recaller)
            ->post(route('logout'))
            ->assertRedirect('/')
            ->assertCookieExpired($recallerName);

        $this->assertGuest('web');
        $this->assertNotSame($rememberToken, $user->fresh()->getRememberToken());
    }
}
