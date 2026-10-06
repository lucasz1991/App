<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerPortalAuthController;
use App\Http\Middleware\EnsureCustomerPortalAccess;
use App\Mail\CustomerPortalMail;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalDelivery;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalAccessService;
use App\Services\CustomerPortal\CustomerPortalDeliveryService;
use App\Services\CustomerPortal\CustomerPortalInvitationService;
use App\Services\CustomerPortal\CustomerPortalMfaService;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerPortalAccessTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private CustomerContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_06_110000_create_customer_portal_access.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Gate::define('customers.portal.manage', fn ($u) => $u instanceof User && $u->status);
        Gate::define('customers.portal.publish', fn ($u) => $u instanceof User && $u->status);
        config(['customer_portal.delivery_enabled' => false, 'app.url' => 'http://localhost']);
        Mail::fake();
        Bus::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic Portal Customer', 'is_active' => true]);
        $this->contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic Contact', 'email' => 'portal-synthetic@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        Route::middleware('web')->group(function (): void {
            Route::get('/test-cp-login', [CustomerPortalAuthController::class, 'showLogin']);
            Route::post('/test-cp-login', [CustomerPortalAuthController::class, 'login']);
            Route::post('/test-cp-logout', [CustomerPortalAuthController::class, 'logout']);
            Route::get('/test-cp-invite/{token}', [CustomerPortalAuthController::class, 'invitation']);
            Route::get('/test-cp-private', fn () => response('private'))->middleware(EnsureCustomerPortalAccess::class);
            Route::get('/test-cp-mfa-setup', [CustomerPortalAuthController::class, 'mfaSetup'])->middleware(EnsureCustomerPortalAccess::class);
            Route::post('/test-cp-mfa-begin', [CustomerPortalAuthController::class, 'beginMfa'])->middleware(EnsureCustomerPortalAccess::class);
            Route::post('/test-cp-mfa-confirm', [CustomerPortalAuthController::class, 'confirmMfa'])->middleware(EnsureCustomerPortalAccess::class);
            Route::post('/test-cp-mfa-challenge', [CustomerPortalAuthController::class, 'verifyMfa'])->middleware(EnsureCustomerPortalAccess::class);
            Route::post('/test-cp-wire', fn () => response('must require verified factor'))->middleware(EnsureCustomerPortalAccess::class);
        });
    }

    private function settings(bool $enabled = true, int $revision = 0, array $extra = []): CustomerPortalSetting
    {
        return app(CustomerPortalAccessService::class)->saveSetting($this->customer->id, $revision, $extra + ['enabled' => $enabled, 'modules' => CustomerPortalSetting::MODULES, 'automation_mode' => 'manual', 'auto_reject' => false, 'notifications' => ['decisions', 'requests']], $this->admin);
    }

    private function pending(string $role = 'coordinator', array $extra = []): CustomerPortalMembership
    {
        return app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $this->contact->id, 0, $extra + ['role' => $role, 'status' => 'pending', 'location_ids' => [], 'history_from' => null], $this->admin);
    }

    private function token(CustomerPortalInvitation $invitation): string
    {
        $delivery = CustomerPortalDelivery::where('invitation_id', $invitation->id)->firstOrFail();

        return basename($delivery->payload['url']);
    }

    private function invite(CustomerPortalMembership $membership): CustomerPortalInvitation
    {
        return app(CustomerPortalInvitationService::class)->invite($this->customer->id, $membership->id, $membership->revision, $this->admin);
    }

    private function active(): CustomerPortalIdentity
    {
        $this->settings();
        $membership = $this->pending();
        $invitation = CustomerPortalInvitation::where('membership_id', $membership->id)->latest('id')->firstOrFail();

        return app(CustomerPortalInvitationService::class)->accept($this->token($invitation), ['name' => 'Synthetic Identity', 'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!']);
    }

    private function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected protected action.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $status);
        }
    }

    public function test_additive_schema_default_off_and_rollback_protects_data(): void
    {
        $this->assertTrue(CustomerPortalSchema::ready());
        $this->assertFalse(config('customer_portal.delivery_enabled'));
        $this->assertSame(0, CustomerPortalSetting::count());
        (require database_path('migrations/2026_10_06_110000_create_customer_portal_access.php'))->up();
        $this->assertSame(0, CustomerPortalIdentity::count());
        $this->settings(false);
        try {
            (require database_path('migrations/2026_10_06_110000_create_customer_portal_access.php'))->down();
            $this->fail('Expected populated rollback guard.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be removed', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('customer_portal_settings'));
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_missing_schema_blocks_only_new_portal(): void
    {
        Schema::drop('customer_portal_audits');
        $this->assertFalse(CustomerPortalSchema::ready());
        $this->denied(fn () => app(CustomerPortalScope::class)->manageableCustomers($this->admin), 503);
        $this->assertSame(1, Customer::count());
    }

    public function test_explicit_activation_queues_configured_contacts_without_real_delivery(): void
    {
        $this->settings(false);
        $membership = $this->pending();
        $this->assertSame(0, CustomerPortalInvitation::count());
        $setting = $this->settings(true, 1);
        $this->assertTrue($setting->enabled);
        $this->assertSame('pending', $membership->fresh()->status);
        $this->assertSame(1, CustomerPortalInvitation::count());
        $this->assertSame('disabled', app(CustomerPortalDeliveryService::class)->deliver(CustomerPortalDelivery::first()->id));
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_identical_setting_and_membership_save_does_not_revoke_or_resend(): void
    {
        $setting = $this->settings();
        $membership = $this->pending();
        $invite = CustomerPortalInvitation::firstOrFail();
        $same = $this->settings(true, $setting->revision);
        $this->assertSame($setting->revision, $same->revision);
        $sameMembership = app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $this->contact->id, $membership->revision, ['role' => $membership->role, 'status' => $membership->status, 'capabilities' => $membership->capabilities, 'location_ids' => [], 'history_from' => null], $this->admin);
        $this->assertSame($membership->revision, $sameMembership->revision);
        $this->assertNull($invite->fresh()->revoked_at);
        $this->assertSame(1, CustomerPortalInvitation::count());
    }

    public function test_activation_nominates_only_one_contact_and_rejects_ambiguous_bulk_invitation(): void
    {
        $this->settings(false);
        $this->pending();
        $otherContact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Second Synthetic Contact', 'email' => 'second-portal@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $otherContact->id, 0, ['role' => 'reader', 'status' => 'pending', 'location_ids' => []], $this->admin);
        $this->denied(fn () => $this->settings(true, 1), 422);
        $this->assertFalse(CustomerPortalSetting::first()->enabled);
        $this->assertSame(0, CustomerPortalInvitation::count());
        $this->settings(true, 1, ['activation_contact_id' => $this->contact->id]);
        $this->assertSame(1, CustomerPortalInvitation::count());
        $this->assertSame($this->contact->email, CustomerPortalDelivery::first()->payload['recipient_email']);
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_tokens_encrypted_hashed_one_use_and_get_does_not_consume(): void
    {
        $this->settings();
        $membership = $this->pending();
        $invitation = CustomerPortalInvitation::firstOrFail();
        $token = $this->token($invitation);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $raw = DB::table('customer_portal_deliveries')->first()->payload;
        $this->assertStringNotContainsString($token, $raw);
        $this->assertStringNotContainsString($this->contact->email, $raw);
        $this->get('/test-cp-invite/'.$token)->assertOk()->assertSee('Einladung annehmen');
        $this->get('/test-cp-invite/'.$token)->assertOk();
        $this->assertNull($invitation->fresh()->consumed_at);
        $identity = app(CustomerPortalInvitationService::class)->accept($token, ['name' => 'Synthetic Identity', 'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!']);
        $this->assertNotNull($identity->email_verified_at);
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame(1, User::count());
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->inspect($token), 410);
    }

    public function test_resend_invalidates_old_link_and_changes_are_revision_bound(): void
    {
        $this->settings();
        $membership = $this->pending();
        $old = CustomerPortalInvitation::firstOrFail();
        $new = $this->invite($membership);
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->inspect($this->token($old)), 410);
        $this->assertNotSame($old->token_hash, $new->token_hash);
        $this->denied(fn () => $this->invite($membership), 409);
        $this->travel(49)->hours();
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->inspect($this->token($new)), 410);
    }

    public function test_contact_email_or_revision_change_invalidates_invitation(): void
    {
        $this->settings();
        $this->pending();
        $invite = CustomerPortalInvitation::firstOrFail();
        $this->contact->forceFill(['email' => 'changed@example.test'])->save();
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->inspect($this->token($invite)), 410);
    }

    public function test_revoked_membership_requires_new_intentional_invitation(): void
    {
        $identity = $this->active();
        $membership = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        $revoked = app(CustomerPortalAccessService::class)->revoke($membership->id, $membership->revision, $this->admin);
        $this->denied(fn () => app(CustomerPortalScope::class)->membership($identity, $this->customer->id), 403);
        $new = $this->invite($revoked);
        $this->assertNull($revoked->fresh()->revoked_at);
        $this->assertSame('pending', $revoked->fresh()->status);
        $identity2 = app(CustomerPortalInvitationService::class)->accept($this->token($new), ['current_password' => 'SyntheticPassword123!']);
        $this->assertSame($identity->id, $identity2->id);
        $this->assertSame(1, CustomerPortalIdentity::count());
    }

    public function test_each_active_dependency_rechecked_and_customer_data_isolated(): void
    {
        $identity = $this->active();
        $scope = app(CustomerPortalScope::class);
        $other = Customer::create(['company_name' => 'Other Synthetic Customer', 'is_active' => true]);
        $this->denied(fn () => $scope->membership($identity, $other->id), 403);
        $this->contact->forceFill(['is_active' => false])->save();
        $this->denied(fn () => $scope->membership($identity, $this->customer->id), 403);
        $this->contact->forceFill(['is_active' => true])->save();
        $this->customer->forceFill(['is_active' => false])->save();
        $this->denied(fn () => $scope->membership($identity, $this->customer->id), 403);
        $this->customer->forceFill(['is_active' => true])->save();
        $this->settings(false, 1);
        $this->denied(fn () => $scope->membership($identity, $this->customer->id), 403);
    }

    public function test_revocation_does_not_require_stale_contact_or_location_to_be_active(): void
    {
        $identity = $this->active();
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        $this->contact->forceFill(['is_active' => false, 'email' => null])->save();
        $member->forceFill(['location_ids' => [987654]])->save();
        $revoked = app(CustomerPortalAccessService::class)->revoke($member->id, $member->revision, $this->admin);
        $this->assertSame('revoked', $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $same = app(CustomerPortalAccessService::class)->revoke($revoked->id, $revoked->revision, $this->admin);
        $this->assertSame($revoked->revision, $same->revision);
        $this->denied(fn () => app(CustomerPortalScope::class)->membership($identity, $this->customer->id), 403);
    }

    public function test_role_capabilities_modules_and_mfa_are_independent(): void
    {
        $identity = $this->active();
        $scope = app(CustomerPortalScope::class);
        $membership = $scope->membership($identity, $this->customer->id, 'offers.accept');
        $this->settings(true, 1, ['modules' => ['orders'], 'require_mfa' => true]);
        $this->denied(fn () => $scope->membership($identity, $this->customer->id, 'offers.accept'), 403);
        $this->settings(true, 2, ['require_mfa' => true]);
        $this->denied(fn () => $scope->membership($identity, $this->customer->id, 'offers.accept'), 403);
        $this->denied(fn () => app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $this->contact->id, $membership->revision, ['role' => 'reader', 'status' => 'active', 'capabilities' => ['offers.accept'], 'location_ids' => []], $this->admin), 422);
    }

    public function test_nonadmin_needs_explicit_percustomer_grant_and_cannot_self_grant(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $scope = app(CustomerPortalScope::class);
        $this->assertSame(0, $scope->manageableCustomers($manager)->count());
        $this->denied(fn () => $scope->authorizeManager($manager, $this->customer->id), 403);
        app(CustomerPortalAccessService::class)->grantManager($this->customer->id, $manager->id, ['customers.portal.manage'], $this->admin);
        $this->assertSame([$this->customer->id], $scope->manageableCustomers($manager)->pluck('id')->all());
        $this->denied(fn () => $scope->authorizeManager($manager, $this->customer->id, 'customers.portal.publish'), 403);
        $this->denied(fn () => app(CustomerPortalAccessService::class)->grantManager($this->customer->id, $manager->id, ['customers.portal.manage'], $manager), 403);
        app(CustomerPortalAccessService::class)->grantManager($this->customer->id, $manager->id, [], $this->admin);
        $this->denied(fn () => $scope->authorizeManager($manager, $this->customer->id), 403);
    }

    public function test_customer_access_right_alone_cannot_enable_binding_automation(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('customers.portal.manage', fn ($u) => true);
        Gate::define('customers.portal.automation', fn ($u) => $u->isAdmin());
        app(CustomerPortalAccessService::class)->grantManager($this->customer->id, $manager->id, ['customers.portal.manage'], $this->admin);
        $this->denied(fn () => app(CustomerPortalAccessService::class)->saveSetting($this->customer->id, 0, ['enabled' => false, 'modules' => [], 'automation_mode' => 'accept', 'auto_reject' => true, 'booking_authority' => true, 'notifications' => []], $manager), 403);
        $this->assertSame(0, CustomerPortalSetting::count());
        app(CustomerPortalAccessService::class)->grantManager($this->customer->id, $manager->id, ['customers.portal.manage', 'customers.portal.automation'], $this->admin);
        $this->assertStringContainsString('customers.portal.automation', DB::table('customer_portal_manager_grants')->where('user_id', $manager->id)->value('abilities'));
    }

    public function test_location_scope_fails_closed_without_explicit_source_mapping(): void
    {
        $identity = $this->active();
        $location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic Yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $this->contact->id, $member->revision, ['role' => 'coordinator', 'status' => 'active', 'location_ids' => [$location->id]], $this->admin);
        Order::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic Duty', 'status' => 'confirmed', 'priority' => 'normal', 'starts_at' => now(), 'ends_at' => now()->addHours(2), 'timezone' => 'Europe/Berlin']);
        $this->assertSame(0, app(CustomerPortalScope::class)->orders($identity, $this->customer->id)->count());
    }

    public function test_future_history_keeps_old_orders_out_of_customer_scope(): void
    {
        $identity = $this->active();
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        app(CustomerPortalAccessService::class)->saveMembership($this->customer->id, $this->contact->id, $member->revision, ['role' => 'coordinator', 'status' => 'active', 'location_ids' => [], 'history_from' => now()->addDay()->format('Y-m-d')], $this->admin);
        Order::create(['customer_id' => $this->customer->id, 'title' => 'Old Synthetic Duty', 'status' => 'confirmed', 'priority' => 'normal', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2), 'timezone' => 'Europe/Berlin']);
        $this->assertSame(0, app(CustomerPortalScope::class)->orders($identity, $this->customer->id)->count());
    }

    public function test_password_reset_does_not_reactivate_revoked_access(): void
    {
        $identity = $this->active();
        app(CustomerPortalInvitationService::class)->passwordReset($identity->email);
        $reset = CustomerPortalInvitation::where('purpose', 'reset')->firstOrFail();
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        app(CustomerPortalAccessService::class)->revoke($member->id, $member->revision, $this->admin);
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->resetPassword($this->token($reset), ['password' => 'NewSyntheticPassword123!', 'password_confirmation' => 'NewSyntheticPassword123!']), 410);
        $this->assertTrue(Hash::check('SyntheticPassword123!', $identity->fresh()->password));
        $this->assertSame('revoked', $member->fresh()->status);
    }

    public function test_successful_password_reset_invalidates_old_identity_revision_and_link(): void
    {
        $identity = $this->active();
        app(CustomerPortalInvitationService::class)->passwordReset($identity->email);
        $reset = CustomerPortalInvitation::where('purpose', 'reset')->firstOrFail();
        $changed = app(CustomerPortalInvitationService::class)->resetPassword($this->token($reset), ['password' => 'NewSyntheticPassword123!', 'password_confirmation' => 'NewSyntheticPassword123!']);
        $this->assertSame($identity->revision + 1, $changed->revision);
        $this->assertTrue(Hash::check('NewSyntheticPassword123!', $changed->password));
        $this->denied(fn () => app(CustomerPortalScope::class)->membership($identity, $this->customer->id), 403);
        $this->denied(fn () => app(CustomerPortalInvitationService::class)->inspect($this->token($reset), 'reset'), 410);
    }

    public function test_delivery_checks_fresh_revocation_and_stale_contact_before_mail(): void
    {
        $this->settings();
        $this->pending();
        $delivery = CustomerPortalDelivery::firstOrFail();
        config(['customer_portal.delivery_enabled' => true]);
        $this->contact->forceFill(['email' => 'stale@example.test'])->save();
        $this->assertSame('canceled', app(CustomerPortalDeliveryService::class)->deliver($delivery->id));
        Mail::assertNothingSent();
    }

    public function test_mail_fake_only_delivery_is_once_and_subject_and_content_escaped(): void
    {
        $this->settings();
        $this->pending();
        $delivery = CustomerPortalDelivery::firstOrFail();
        config(['customer_portal.delivery_enabled' => true]);
        $service = app(CustomerPortalDeliveryService::class);
        $this->assertSame('sent', $service->deliver($delivery->id));
        $this->assertSame('sent', $service->deliver($delivery->id));
        Mail::assertSent(CustomerPortalMail::class, 1);
        $mail = new CustomerPortalMail('decisions', ['message' => '<script>unsafe</script>']);
        $this->assertStringContainsString('&lt;script&gt;', $mail->render());
        $this->assertStringNotContainsString('<script>', $mail->render());
    }

    public function test_unknown_transport_outcome_never_auto_retries(): void
    {
        $this->settings();
        $this->pending();
        $delivery = CustomerPortalDelivery::firstOrFail();
        config(['customer_portal.delivery_enabled' => true]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic unknown transport.'));
        $service = app(CustomerPortalDeliveryService::class);
        $this->assertSame('unknown', $service->deliver($delivery->id));
        $this->assertSame('unknown', $service->deliver($delivery->id));
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame('transport_outcome_unknown', $delivery->fresh()->failure_code);
    }

    public function test_delivery_requires_transaction_and_dedup_is_customer_bound(): void
    {
        $identity = $this->active();
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        $service = app(CustomerPortalDeliveryService::class);
        $this->denied(fn () => $service->enqueue($this->customer->id, $member->id, 'decisions', 'synthetic-decision', ['message' => 'Synthetic']), 409);
        $a = DB::transaction(fn () => $service->enqueue($this->customer->id, $member->id, 'decisions', 'synthetic-decision', ['message' => 'Synthetic']));
        $b = DB::transaction(fn () => $service->enqueue($this->customer->id, $member->id, 'decisions', 'synthetic-decision', ['message' => 'Different ignored duplicate']));
        $this->assertSame($a->id, $b->id);
        $this->assertSame('Synthetic', $b->payload['message']);
        Mail::assertNothingSent();
    }

    public function test_portal_guard_and_staff_guard_remain_separate_and_stale_session_denied(): void
    {
        $identity = $this->active();
        $this->actingAs($this->admin, 'web');
        $this->post('/test-cp-login', ['email' => $identity->email, 'password' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal');
        $this->assertAuthenticatedAs($identity, 'customer_portal');
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->get('/test-cp-private')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $identity->forceFill(['revision' => $identity->revision + 1])->save();
        $this->get('/test-cp-private')->assertRedirect('/kundenportal/anmelden');
        $this->assertGuest('customer_portal');
        $this->assertAuthenticatedAs($this->admin, 'web');
    }

    public function test_staff_credentials_cannot_authenticate_as_customer_and_login_limited(): void
    {
        $this->post('/test-cp-login', ['email' => $this->admin->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('customer_portal');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/test-cp-login', ['email' => 'not-real@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->assertGuest('customer_portal');
        Mail::assertNothingSent();
    }

    public function test_generic_reset_notification_cannot_bypass_disabled_portal_outbox(): void
    {
        $identity = $this->active();
        try {
            $identity->sendPasswordResetNotification('synthetic-ignored-token');
            $this->fail('Expected dedicated outbox requirement.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('dedicated invitation outbox', $exception->getMessage());
        }
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function mfaIdentity(): CustomerPortalIdentity
    {
        $identity = $this->active();
        $this->bindPrincipal($identity);

        return $identity;
    }

    private function bindPrincipal(CustomerPortalIdentity $identity): void
    {
        Auth::guard('customer_portal')->login($identity, false);
        session()->put(['customer_portal_identity_revision' => $identity->revision, 'customer_portal_authenticated_at' => now()->timestamp]);
    }

    private function totp(string $secret, int $offset = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->timestamp, 30) + $offset);
    }

    private function enabledMfa(): array
    {
        $identity = $this->mfaIdentity();
        $service = app(CustomerPortalMfaService::class);
        $pending = $service->begin($identity, ['password' => 'SyntheticPassword123!']);
        $result = $service->confirm($identity, ['code' => $this->totp($pending->two_factor_pending_secret)]);
        $this->bindPrincipal($result['identity']);

        return $result;
    }

    private function invalidMfa(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected invalid factor.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_mfa_setup_password_required_pending_encrypted_and_only_current_session_sees_secret(): void
    {
        $identity = $this->mfaIdentity();
        $service = app(CustomerPortalMfaService::class);
        $this->invalidMfa(fn () => $service->begin($identity, ['password' => 'wrong']));
        $this->assertNull($identity->fresh()->two_factor_pending_secret);
        $pending = $service->begin($identity, ['password' => 'SyntheticPassword123!']);
        $raw = DB::table('customer_portal_identities')->where('id', $identity->id)->first();
        $this->assertNotSame($pending->two_factor_pending_secret, $raw->two_factor_pending_secret);
        $this->assertNull($pending->two_factor_secret);
        $state = $service->state($identity);
        $this->assertTrue($state['pending']);
        $this->assertFalse($state['enabled']);
        $this->assertStringStartsWith('<svg', $state['qr_svg']);
        $this->assertArrayNotHasKey('two_factor_pending_secret', $pending->toArray());
        session()->regenerate();
        $state2 = $service->state($identity);
        $this->assertFalse($state2['pending']);
        $this->assertNull($state2['secret']);
        $this->denied(fn () => $service->confirm($identity, ['code' => $this->totp($pending->two_factor_pending_secret)]), 409);
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_mfa_expired_pending_does_not_activate_and_bad_code_preserves_pending(): void
    {
        $identity = $this->mfaIdentity();
        $service = app(CustomerPortalMfaService::class);
        $pending = $service->begin($identity, ['password' => 'SyntheticPassword123!']);
        $this->invalidMfa(fn () => $service->confirm($identity, ['code' => 'not-digits']));
        $this->assertNotNull($identity->fresh()->two_factor_pending_secret);
        $this->travel(11)->minutes();
        $this->denied(fn () => $service->confirm($identity, ['code' => $this->totp($pending->two_factor_pending_secret)]), 409);
        $this->assertNull($identity->fresh()->two_factor_confirmed_at);
        $this->assertNull($service->state($identity)['secret']);
    }

    public function test_mfa_confirmation_atomic_bumps_revision_and_recovery_codes_are_hashes_inside_encryption(): void
    {
        $result = $this->enabledMfa();
        $identity = $result['identity'];
        $this->assertSame(2, $identity->revision);
        $this->assertNotNull($identity->two_factor_confirmed_at);
        $this->assertNull($identity->two_factor_pending_secret);
        $this->assertCount(8, $result['codes']);
        $this->assertCount(8, array_unique($result['codes']));
        $raw = DB::table('customer_portal_identities')->where('id', $identity->id)->first();
        foreach ($result['codes'] as $code) {
            $this->assertStringNotContainsString($code, $raw->two_factor_recovery_codes);
            $this->assertNotContains($code, $identity->two_factor_recovery_codes);
        }
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $identity->toArray());
        $this->assertStringNotContainsString($identity->two_factor_secret, $raw->two_factor_secret);
        $state = app(CustomerPortalMfaService::class)->state($identity);
        $this->assertTrue($state['enabled']);
        $this->assertSame(8, $state['remaining_codes']);
        $this->assertNull($state['secret']);
        $this->denied(fn () => app(CustomerPortalMfaService::class)->confirm($identity, ['code' => $this->totp($identity->two_factor_secret)]), 409);
    }

    public function test_mfa_totp_counter_replay_is_rejected_including_future_window(): void
    {
        $identity = $this->mfaIdentity();
        $service = app(CustomerPortalMfaService::class);
        $pending = $service->begin($identity, ['password' => 'SyntheticPassword123!']);
        $code = $this->totp($pending->two_factor_pending_secret, 1);
        $result = $service->confirm($identity, ['code' => $code]);
        $this->bindPrincipal($result['identity']);
        $this->assertSame(intdiv(now()->timestamp, 30) + 1, $result['identity']->two_factor_last_counter);
        $this->invalidMfa(fn () => $service->challenge($result['identity'], ['code' => $code]));
        $this->travel(61)->seconds();
        $verified = $service->challenge($result['identity'], ['code' => $this->totp($result['identity']->two_factor_secret)]);
        $this->assertGreaterThan($result['identity']->two_factor_last_counter, $verified->two_factor_last_counter);
    }

    public function test_mfa_recovery_code_consumed_once_without_manufacturing_new_codes(): void
    {
        $result = $this->enabledMfa();
        $service = app(CustomerPortalMfaService::class);
        $verified = $service->challenge($result['identity'], ['recovery_code' => strtolower($result['codes'][0])]);
        $this->assertCount(7, $verified->two_factor_recovery_codes);
        $this->invalidMfa(fn () => $service->challenge($verified, ['recovery_code' => $result['codes'][0]]));
        $this->assertCount(7, $verified->fresh()->two_factor_recovery_codes);
        $this->assertSame(1, CustomerPortalAudit::where('action', 'mfa_recovery_used')->count());
    }

    public function test_mfa_regeneration_requires_password_and_factor_and_invalidates_all_old_codes(): void
    {
        $result = $this->enabledMfa();
        $service = app(CustomerPortalMfaService::class);
        $this->invalidMfa(fn () => $service->regenerateCodes($result['identity'], ['password' => 'wrong', 'recovery_code' => $result['codes'][0]]));
        $this->assertCount(8, $result['identity']->fresh()->two_factor_recovery_codes);
        $regenerated = $service->regenerateCodes($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][0]]);
        $this->assertSame($result['identity']->revision + 1, $regenerated['identity']->revision);
        $this->assertCount(8, $regenerated['codes']);
        $this->bindPrincipal($regenerated['identity']);
        foreach ($result['codes'] as $old) {
            $this->invalidMfa(fn () => $service->challenge($regenerated['identity'], ['recovery_code' => $old]));
        }
        $this->assertSame(7, count($service->challenge($regenerated['identity'], ['recovery_code' => $regenerated['codes'][0]])->two_factor_recovery_codes));
    }

    public function test_mfa_device_replacement_uses_old_factor_and_remains_safe_if_confirmation_expires(): void
    {
        $result = $this->enabledMfa();
        $service = app(CustomerPortalMfaService::class);
        $this->invalidMfa(fn () => $service->begin($result['identity'], ['password' => 'SyntheticPassword123!']));
        $pending = $service->begin($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][0]]);
        $this->assertSame($result['identity']->two_factor_secret, $pending->two_factor_secret);
        $this->assertNotSame($pending->two_factor_secret, $pending->two_factor_pending_secret);
        $this->travel(11)->minutes();
        $this->denied(fn () => $service->confirm($result['identity'], ['code' => $this->totp($pending->two_factor_pending_secret)]), 409);
        $this->assertSame($result['identity']->two_factor_secret, $result['identity']->fresh()->two_factor_secret);
        $replacement = $service->begin($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][1]]);
        $changed = $service->confirm($result['identity'], ['code' => $this->totp($replacement->two_factor_pending_secret)]);
        $this->assertSame($replacement->two_factor_pending_secret, $changed['identity']->two_factor_secret);
        $this->assertSame($result['identity']->revision + 1, $changed['identity']->revision);
    }

    public function test_mfa_required_by_any_customer_cannot_be_disabled_but_device_can_be_replaced(): void
    {
        $result = $this->enabledMfa();
        $this->settings(true, 1, ['require_mfa' => true]);
        $service = app(CustomerPortalMfaService::class);
        $this->assertTrue($service->state($result['identity'])['required']);
        $this->denied(fn () => $service->disable($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][0]]), 409);
        $this->assertCount(8, $result['identity']->fresh()->two_factor_recovery_codes);
        $this->assertNotNull($service->begin($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][0]])->two_factor_pending_secret);
    }

    public function test_mfa_optional_disable_needs_password_factor_and_clears_all_secrets_and_revision(): void
    {
        $result = $this->enabledMfa();
        $service = app(CustomerPortalMfaService::class);
        $this->invalidMfa(fn () => $service->disable($result['identity'], ['password' => 'wrong', 'recovery_code' => $result['codes'][0]]));
        $disabled = $service->disable($result['identity'], ['password' => 'SyntheticPassword123!', 'recovery_code' => $result['codes'][0]]);
        $this->assertSame($result['identity']->revision + 1, $disabled->revision);
        $this->assertNull($disabled->two_factor_secret);
        $this->assertNull($disabled->two_factor_recovery_codes);
        $this->assertNull($disabled->two_factor_confirmed_at);
        $this->denied(fn () => $service->state($result['identity']), 403);
    }

    public function test_mfa_stale_session_and_revoked_membership_cannot_be_verified(): void
    {
        $result = $this->enabledMfa();
        $service = app(CustomerPortalMfaService::class);
        session()->put('customer_portal_identity_revision', 999);
        $this->denied(fn () => $service->challenge($result['identity'], ['recovery_code' => $result['codes'][0]]), 403);
        $this->bindPrincipal($result['identity']);
        $member = app(CustomerPortalScope::class)->membership($result['identity'], $this->customer->id);
        app(CustomerPortalAccessService::class)->revoke($member->id, $member->revision, $this->admin);
        $this->denied(fn () => $service->challenge($result['identity'], ['recovery_code' => $result['codes'][0]]), 403);
        $this->assertCount(8, $result['identity']->fresh()->two_factor_recovery_codes);
    }

    public function test_mfa_controller_one_time_codes_page_no_cache_and_required_action_acceptance_bound(): void
    {
        $identity = $this->mfaIdentity();
        $service = app(CustomerPortalMfaService::class);
        $this->post('/test-cp-mfa-begin', ['password' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal/sicherheit');
        $this->withCookie(config('session.cookie'), session()->getId());
        $pending = $identity->fresh();
        $this->get('/test-cp-mfa-setup')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Einrichtung bestätigen');
        $response = $this->post('/test-cp-mfa-confirm', ['code' => $this->totp($pending->two_factor_pending_secret)]);
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Wiederherstellungscodes');
        $this->withCookie(config('session.cookie'), session()->getId());
        $fresh = $identity->fresh();
        $this->assertSame($fresh->revision, session('customer_portal_identity_revision'));
        $this->assertSame($fresh->revision, session('customer_portal_mfa_revision'));
        $this->get('/test-cp-mfa-setup')->assertOk()->assertSee('Authenticator aktiv')->assertDontSee('Einmalige Wiederherstellungscodes');
        $this->settings(true, 1, ['require_mfa' => true]);
        $this->assertSame($this->customer->id, app(CustomerPortalScope::class)->membership($fresh, $this->customer->id, 'offers.accept')->customer_id);
        $this->travel(16)->minutes();
        $this->denied(fn () => app(CustomerPortalScope::class)->membership($fresh, $this->customer->id, 'offers.accept'), 403);
        $this->post('/test-cp-mfa-challenge', ['code' => $this->totp($fresh->two_factor_secret)])->assertRedirect('/kundenportal');
        $this->assertSame($this->customer->id, app(CustomerPortalScope::class)->membership($fresh, $this->customer->id, 'offers.accept')->customer_id);
        Mail::assertNothingSent();
    }

    public function test_enabled_mfa_password_login_requires_factor_before_any_private_data_and_json_actions(): void
    {
        $result = $this->enabledMfa();
        $this->post('/kundenportal/anmelden', ['email' => $result['identity']->email, 'password' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal/bestaetigung');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/test-cp-private')->assertRedirect('/kundenportal/bestaetigung');
        $this->getJson('/test-cp-private')->assertForbidden();
        $this->postJson('/test-cp-wire', ['controller' => CustomerPortalAuthController::class, 'memo' => ['path' => 'kundenportal/bestaetigung', 'method' => 'GET']])->assertForbidden();
        $this->get('/kundenportal/sicherheit')->assertOk()->assertSee('Authenticator aktiv');
        $this->post('/kundenportal/bestaetigung', ['recovery_code' => $result['codes'][0]])->assertRedirect('/kundenportal');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/test-cp-private')->assertOk();
        $this->getJson('/test-cp-private')->assertOk();
        $this->assertSame($result['identity']->revision, session('customer_portal_mfa_login_revision'));
        $this->assertSame(7, count($result['identity']->fresh()->two_factor_recovery_codes));
        $this->assertGuest('web');
    }

    public function test_required_mfa_without_setup_routes_to_security_and_never_releases_data(): void
    {
        $identity = $this->active();
        $this->settings(true, 1, ['require_mfa' => true]);
        $this->post('/kundenportal/anmelden', ['email' => $identity->email, 'password' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal/sicherheit');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/test-cp-private')->assertRedirect('/kundenportal/sicherheit');
        $this->getJson('/test-cp-private')->assertForbidden();
        $this->get('/kundenportal/sicherheit')->assertOk()->assertSee('Authenticator einrichten');
        $this->post('/kundenportal/sicherheit/einrichten', ['password' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal/sicherheit');
        $secret = $identity->fresh()->two_factor_pending_secret;
        $this->post('/kundenportal/sicherheit/bestaetigen', ['code' => $this->totp($secret)])->assertOk()->assertSee('Wiederherstellungscodes');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/test-cp-private')->assertOk();
        $this->assertGuest('web');
        Mail::assertNothingSent();
    }

    public function test_real_hex_invitation_routes_get_nonconsuming_post_accept_once_without_staff_login(): void
    {
        $this->settings();
        $this->pending();
        $invitation = CustomerPortalInvitation::firstOrFail();
        $token = $this->token($invitation);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->get('/kundenportal/einladung/'.$token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull($invitation->fresh()->consumed_at);
        $this->post('/kundenportal/einladung/'.$token, ['name' => 'Synthetic HTTP Customer', 'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!'])->assertRedirect('/kundenportal');
        $identity = CustomerPortalIdentity::firstOrFail();
        $this->assertAuthenticatedAs($identity, 'customer_portal');
        $this->assertGuest('web');
        $this->assertNotNull($invitation->fresh()->consumed_at);
        $this->get('/kundenportal/einladung/'.$token)->assertGone();
        $this->post('/kundenportal/einladung/'.$token, ['name' => 'Replay', 'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!'])->assertGone();
        $this->assertSame(1, CustomerPortalIdentity::count());
        $this->assertSame(1, User::count());
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_real_hex_password_reset_routes_do_not_consume_get_or_restore_revoked_membership(): void
    {
        $identity = $this->active();
        $this->post('/kundenportal/passwort', ['email' => $identity->email])->assertSessionHas('status');
        $invitation = CustomerPortalInvitation::where('purpose', 'reset')->firstOrFail();
        $token = $this->token($invitation);
        $this->get('/kundenportal/passwort/'.$token)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull($invitation->fresh()->consumed_at);
        $member = app(CustomerPortalScope::class)->membership($identity, $this->customer->id);
        app(CustomerPortalAccessService::class)->revoke($member->id, $member->revision, $this->admin);
        $this->post('/kundenportal/passwort/'.$token, ['password' => 'NewSyntheticPassword123!', 'password_confirmation' => 'NewSyntheticPassword123!'])->assertGone();
        $this->assertSame('revoked', $member->fresh()->status);
        $this->assertTrue(Hash::check('SyntheticPassword123!', $identity->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_invalid_mfa_forms_never_flash_code_recovery_or_password_and_json_only_returns_errors(): void
    {
        $result = $this->enabledMfa();
        $this->post('/kundenportal/bestaetigung', ['code' => '', 'recovery_code' => 'invalid-sensitive-recovery'])->assertSessionHasErrors('recovery_code');
        $this->assertSame([], session('_old_input'));
        $this->assertStringNotContainsString('invalid-sensitive-recovery', json_encode(session()->all()));
        $this->withCookie(config('session.cookie'), session()->getId());
        $response = $this->postJson('/kundenportal/bestaetigung', ['recovery_code' => 'invalid-sensitive-recovery']);
        $response->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
        $this->assertStringNotContainsString('invalid-sensitive-recovery', $response->getContent());
        $this->assertSame(8, count($result['identity']->fresh()->two_factor_recovery_codes));
    }
}
