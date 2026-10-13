<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeAccountInvitationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_07_22_000002_create_employee_document_requirements_table.php',
            '2026_07_18_000001_create_activity_log_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Http::preventStrayRequests();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'email' => 'invite-fixture@example.com', 'email_verified_at' => null]);
    }

    private function profile(?User $actor = null)
    {
        return Livewire::actingAs($actor ?? $this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
    }

    private function manager(bool $view = true): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => ['employees.view' => $view]]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        return $manager->fresh();
    }

    private function invitationUrl(): string
    {
        $this->profile()->call('sendAccountInvitation', $this->employee->email)->assertHasNoErrors();
        $notification = Notification::sent($this->employee, EmployeeAccountInvitation::class)->sole();
        $url = $notification->toMail($this->employee)->actionUrl;
        Auth::logout();

        return $url;
    }

    private function passwordInput(): array
    {
        return ['password' => 'New-Fixture-Password-624!', 'password_confirmation' => 'New-Fixture-Password-624!'];
    }

    public function test_tab_morph_preserves_alert_presentation_but_keeps_status_and_action_live(): void
    {
        $component = $this->profile();

        foreach (['userDetails', 'operationsOverview', 'userDetails'] as $tab) {
            $component->call('setProfileTab', $tab)->assertSuccessful();
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $alerts = $xpath->query('//*[@data-account-invitation]');
            $this->assertCount(1, $alerts);
            $alert = $alerts->item(0);
            $this->assertSame('employee-profile-'.$this->employee->id.'-account-invitation', $alert->getAttribute('wire:key'));
            $this->assertTrue($alert->hasAttribute('wire:ignore.self'));
            $this->assertFalse($alert->hasAttribute('wire:ignore'));
            $this->assertCount(0, $xpath->query('.//*[@*[name()="wire:ignore"]]', $alert));
            $component->assertSee('E-Mail noch nicht bestätigt.');
        }

        $this->employee->update(['status' => false]);
        $component->call('setProfileTab', 'operationsOverview')
            ->assertSee('Konto inaktiv – bitte zuerst aktivieren.');
        Notification::assertNothingSent();
    }

    public function test_admin_invites_existing_account_without_changing_password_role_or_verification(): void
    {
        $before = $this->employee->fresh()->getAttributes();
        $this->profile()->assertSee('Mitarbeiter einladen')->call('sendAccountInvitation', $this->employee->email)
            ->assertHasNoErrors()->assertDispatched('swal:toast');
        Notification::assertSentTo($this->employee, EmployeeAccountInvitation::class);
        $this->assertSame($before, $this->employee->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseHas('activity_log', ['description' => 'employee_account_invitation_sent', 'subject_id' => $this->employee->id]);
    }

    public function test_management_with_employee_access_can_invite_but_regular_staff_cannot(): void
    {
        $this->profile($this->manager())->call('sendAccountInvitation', $this->employee->email)->assertHasNoErrors();
        Notification::assertSentToTimes($this->employee, EmployeeAccountInvitation::class, 1);
        $this->profile($this->manager(false))->assertForbidden();
        $this->profile($this->employee)->assertForbidden();
    }

    public function test_revoked_actor_cannot_send_and_inactive_target_or_changed_email_cannot_be_invited(): void
    {
        $component = $this->profile();
        $this->admin->update(['status' => false]);
        $component->call('sendAccountInvitation', $this->employee->email)->assertForbidden();
        $this->admin->update(['status' => true]);
        $this->employee->update(['status' => false]);
        $this->profile()->call('sendAccountInvitation', $this->employee->email)->assertHasErrors('accountInvitation');
        $this->employee->update(['status' => true]);
        $this->profile()->call('sendAccountInvitation', 'changed@example.com')->assertHasErrors('accountInvitation');
        $this->employee->update(['email' => 'import-fixture@railtime.invalid']);
        $this->profile()->call('sendAccountInvitation', $this->employee->email)->assertHasErrors('accountInvitation');
        Notification::assertNothingSent();
    }

    public function test_send_is_throttled_and_failed_delivery_restores_previous_token(): void
    {
        $oldToken = Password::broker('users')->createToken($this->employee);
        $this->profile()->call('sendAccountInvitation', $this->employee->email)->assertHasErrors('accountInvitation');
        Notification::assertNothingSent();
        $this->travel(61)->seconds();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic mail transport failure'));
        $this->profile()->call('sendAccountInvitation', $this->employee->email)->assertHasErrors('accountInvitation');
        $this->assertTrue(Password::broker('users')->tokenExists($this->employee, $oldToken));
        $this->assertDatabaseMissing('activity_log', ['description' => 'employee_account_invitation_sent']);
    }

    public function test_acceptance_verifies_email_sets_password_logs_in_and_consumes_link(): void
    {
        $url = $this->invitationUrl();
        $this->get($url)->assertOk()->assertSee('Mitarbeiterzugang einrichten');
        $this->assertGuest();
        $this->assertNull($this->employee->fresh()->email_verified_at);
        $this->post($url, $this->passwordInput())->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->employee);
        $this->assertTrue(Hash::check($this->passwordInput()['password'], $this->employee->fresh()->password));
        $this->assertNotNull($this->employee->fresh()->email_verified_at);
        $this->assertSame('staff', $this->employee->fresh()->role);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Auth::logout();
        $this->get($url)->assertForbidden();
        $this->post($url, $this->passwordInput())->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_validation_does_not_consume_invitation_or_verify_email(): void
    {
        $url = $this->invitationUrl();
        $this->post($url, ['password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertNull($this->employee->fresh()->email_verified_at);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertGuest();
    }

    public function test_tampered_or_expired_url_cannot_set_password(): void
    {
        $url = $this->invitationUrl();
        $this->post(str_replace('signature=', 'signature=x', $url), $this->passwordInput())->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->post($url, $this->passwordInput())->assertForbidden();
        $this->assertNull($this->employee->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_deactivation_or_email_change_after_sending_blocks_acceptance(): void
    {
        $url = $this->invitationUrl();
        $this->employee->update(['status' => false]);
        $this->post($url, $this->passwordInput())->assertForbidden();
        $this->employee->update(['status' => true, 'email' => 'other-fixture@example.com']);
        $this->post($url, $this->passwordInput())->assertForbidden();
        $this->assertNull($this->employee->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_resend_invalidates_previous_link_and_posted_email_cannot_change_target(): void
    {
        $old = $this->invitationUrl();
        $this->travel(61)->seconds();
        Notification::fake();
        $new = $this->invitationUrl();
        $this->get($old)->assertForbidden();
        $this->post($new, $this->passwordInput() + ['email' => $this->admin->email])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->employee);
    }

    public function test_existing_two_factor_authentication_is_preserved(): void
    {
        $this->employee->forceFill(['two_factor_secret' => encrypt('SYNTHETICSECRET'), 'two_factor_confirmed_at' => now()])->save();
        $url = $this->invitationUrl();
        $this->post($url, $this->passwordInput())->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $this->employee->id);
        $this->assertGuest();
        $this->assertNotNull($this->employee->fresh()->email_verified_at);
        $this->assertNotNull($this->employee->fresh()->two_factor_secret);
    }
}
