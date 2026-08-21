<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Features;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);
    }

    public function test_complete_email_verification_contract_is_registered(): void
    {
        $this->assertTrue(Features::enabled(Features::emailVerification()));
        $this->assertInstanceOf(MustVerifyEmail::class, new User);

        $expectedRoutes = [
            'verification.notice' => ['GET', 'HEAD'],
            'verification.verify' => ['GET', 'HEAD'],
            'verification.send' => ['POST'],
        ];

        foreach ($expectedRoutes as $routeName => $methods) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertSame($methods, $route->methods());
        }

        $user = User::factory()->unverified()->create();
        $actionUrl = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->assertNotNull($actionUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($actionUrl)));
    }

    public function test_unverified_user_is_redirected_to_notice_and_can_resend_the_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Bestätigungs-E-Mail erneut senden');

        $this->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            function (VerifyEmail $notification) use ($user): bool {
                $actionUrl = $notification->toMail($user)->actionUrl;

                return is_string($actionUrl)
                    && URL::hasValidSignature(Request::create($actionUrl));
            },
        );
    }

    public function test_signed_link_verifies_the_authenticated_users_email(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('dashboard', ['verified' => 1]));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_signed_link_with_a_wrong_email_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1('wrong@example.invalid')],
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_changing_a_verified_email_address_requires_fresh_verification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => $user->name,
            'email' => 'changed-address@example.invalid',
        ]);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );
    }
}
