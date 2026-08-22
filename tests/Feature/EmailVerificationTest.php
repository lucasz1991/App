<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Profile\ProfileIdentityCard;
use App\Models\Device;
use App\Models\DeviceEnrollment;
use App\Models\User;
use App\Notifications\DeviceEnrollmentInvitation;
use App\Support\EmployeeWelcomeService;
use Egulias\EmailValidator\EmailValidator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
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
        $this->assertInstanceOf(HasLocalePreference::class, new User);

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
        $this->acceptAnyEmailAddress();

        $user = User::factory()->create();

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => $user->name,
            'email' => 'changed-address@example.test',
        ]);

        $this->assertSame('changed-address@example.test', $user->fresh()->email);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_profile_email_change_rejects_a_missing_mail_domain_without_saving_or_sending(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'verified@example.test',
        ]);

        Livewire::actingAs($user)
            ->test(ProfileIdentityCard::class)
            ->set('email', 'lucas@zacharias-net.d')
            ->call('saveIdentity')
            ->assertHasErrors(['email'])
            ->assertSee(__('app.email_domain_invalid'))
            ->assertNotDispatched('identity-saved');

        $persistedUser = $user->fresh();

        $this->assertSame('verified@example.test', $persistedUser->email);
        $this->assertTrue($persistedUser->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_unchanged_legacy_email_does_not_block_a_name_update(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'legacy@example.invalid',
        ]);

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => 'New Name',
            'email' => 'legacy@example.invalid',
        ]);

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_profile_email_change_turns_an_smtp_rejection_into_an_inline_error_and_rolls_back(): void
    {
        $this->acceptAnyEmailAddress();
        $smtpMessage = '450 4.1.2 Recipient address rejected: Domain not found';

        $this->mock(Dispatcher::class, function (MockInterface $mock) use ($smtpMessage): void {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new UnexpectedResponseException($smtpMessage, 450));
        });

        $user = User::factory()->create([
            'email' => 'verified@example.test',
        ]);

        Livewire::actingAs($user)
            ->test(ProfileIdentityCard::class)
            ->set('email', 'changed-address@example.test')
            ->call('saveIdentity')
            ->assertHasErrors(['email'])
            ->assertSee(__('app.email_verification_delivery_failed'))
            ->assertDontSee($smtpMessage)
            ->assertNotDispatched('identity-saved');

        $persistedUser = $user->fresh();

        $this->assertSame('verified@example.test', $persistedUser->email);
        $this->assertTrue($persistedUser->hasVerifiedEmail());
    }

    public function test_manual_verification_resend_turns_an_smtp_rejection_into_an_inline_error(): void
    {
        $smtpMessage = '450 4.1.2 Recipient address rejected: Domain not found';

        $this->mock(Dispatcher::class, function (MockInterface $mock) use ($smtpMessage): void {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new UnexpectedResponseException($smtpMessage, 450));
        });

        $user = User::factory()->unverified()->create();

        Livewire::actingAs($user)
            ->test(ProfileIdentityCard::class)
            ->call('sendEmailVerification')
            ->assertHasErrors(['email'])
            ->assertSee(__('app.email_verification_delivery_failed'))
            ->assertDontSee($smtpMessage)
            ->assertSet('verificationLinkSent', false);
    }

    public function test_language_switch_is_persisted_for_the_authenticated_user(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)
            ->from('/profile')
            ->get(route('locale.switch', 'en'))
            ->assertRedirect('/profile')
            ->assertSessionHas('locale', 'en');

        $this->assertSame('en', $user->fresh()->locale);

        $this->from('/profile')
            ->get(route('locale.switch', 'de'))
            ->assertRedirect('/profile')
            ->assertSessionHas('locale', 'de');

        $this->assertSame('de', $user->fresh()->locale);
    }

    public function test_verification_and_password_reset_notifications_use_the_recipient_language(): void
    {
        Notification::fake();

        $translations = [
            'de' => [
                'verification_subject' => 'E-Mail-Adresse bestätigen',
                'verification_line' => 'Bitte klicken Sie auf die Schaltfläche unten, um Ihre E-Mail-Adresse zu bestätigen.',
                'reset_subject' => 'Passwort zurücksetzen',
                'reset_line' => 'Sie erhalten diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passworts für Ihr Konto erhalten haben.',
            ],
            'en' => [
                'verification_subject' => 'Verify Email Address',
                'verification_line' => 'Please click the button below to verify your email address.',
                'reset_subject' => 'Reset Password Notification',
                'reset_line' => 'You are receiving this email because we received a password reset request for your account.',
            ],
        ];

        foreach ($translations as $locale => $expected) {
            App::setLocale($locale === 'de' ? 'en' : 'de');

            $user = User::factory()->unverified()->create(['locale' => $locale]);
            $user->sendEmailVerificationNotification();
            $user->sendPasswordResetNotification('known-test-token');

            Notification::assertSentTo(
                $user,
                VerifyEmail::class,
                function (
                    VerifyEmail $notification,
                    array $channels,
                    User $recipient,
                    ?string $sentLocale,
                ) use ($locale, $expected): bool {
                    $this->assertSame(['mail'], $channels);
                    $this->assertSame($locale, $sentLocale);

                    $message = $this->mailMessageInLocale(
                        $sentLocale,
                        $notification,
                        $recipient,
                    );

                    $this->assertSame($expected['verification_subject'], $message->subject);
                    $this->assertSame($expected['verification_subject'], $message->actionText);
                    $this->assertContains($expected['verification_line'], $message->introLines);

                    return true;
                },
            );

            Notification::assertSentTo(
                $user,
                ResetPassword::class,
                function (
                    ResetPassword $notification,
                    array $channels,
                    User $recipient,
                    ?string $sentLocale,
                ) use ($locale, $expected): bool {
                    $this->assertSame(['mail'], $channels);
                    $this->assertSame($locale, $sentLocale);

                    $message = $this->mailMessageInLocale(
                        $sentLocale,
                        $notification,
                        $recipient,
                    );

                    $this->assertSame($expected['reset_subject'], $message->subject);
                    $this->assertSame(
                        $locale === 'de' ? 'Passwort zurücksetzen' : 'Reset Password',
                        $message->actionText,
                    );
                    $this->assertContains($expected['reset_line'], $message->introLines);
                    $this->assertStringContainsString('known-test-token', $message->actionUrl);

                    return true;
                },
            );
        }
    }

    public function test_an_unsupported_user_locale_falls_back_to_the_application_locale(): void
    {
        $user = User::factory()->make(['locale' => 'fr']);

        $this->assertSame(config('app.locale'), $user->preferredLocale());
    }

    public function test_automatic_welcome_content_uses_the_recipient_language(): void
    {
        $service = app(EmployeeWelcomeService::class);

        foreach ([
            'de' => 'Willkommen bei der '.config('app.name').' Mitarbeiter-Applikation',
            'en' => 'Welcome to the '.config('app.name').' employee application',
        ] as $locale => $expectedSubject) {
            App::setLocale($locale === 'de' ? 'en' : 'de');
            $originalLocale = App::getLocale();
            $user = User::factory()->create(['locale' => $locale]);

            $content = $service->contentFor($user);

            $this->assertSame($expectedSubject, $content['subject']);
            $this->assertSame($originalLocale, App::getLocale());
        }
    }

    public function test_device_enrollment_mail_has_german_and_english_content(): void
    {
        $device = (new Device)->forceFill([
            'display_name' => 'Test Laptop',
            'asset_tag' => 'RT-100',
        ]);
        $enrollment = (new DeviceEnrollment)->forceFill([
            'expires_at' => now()->addHour(),
        ]);
        $enrollment->setRelation('device', $device);
        $notification = new DeviceEnrollmentInvitation($enrollment, 'known-enrollment-token');

        foreach ([
            'de' => ['Ihr RailTime-Gerät sicher einrichten', 'Gerät jetzt in RailTime einrichten', 'Gerät: Test Laptop · Inventarnummer RT-100'],
            'en' => ['Set up your RailTime device securely', 'Set up device in RailTime', 'Device: Test Laptop · Asset number RT-100'],
        ] as $locale => [$subject, $action, $deviceLine]) {
            $recipient = User::factory()->make([
                'name' => 'Lucas Zacharias',
                'locale' => $locale,
            ]);

            $message = $this->mailMessageInLocale($recipient->preferredLocale(), $notification, $recipient);

            $this->assertSame($subject, $message->subject);
            $this->assertSame($action, $message->actionText);
            $this->assertContains($deviceLine, $message->introLines);
        }
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

    private function acceptAnyEmailAddress(): void
    {
        $this->mock(EmailValidator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isValid')->andReturnTrue();
        });
    }

    private function mailMessageInLocale(string $locale, object $notification, User $recipient): MailMessage
    {
        $originalLocale = App::getLocale();

        try {
            App::setLocale($locale);

            return $notification->toMail($recipient);
        } finally {
            App::setLocale($originalLocale);
        }
    }
}
