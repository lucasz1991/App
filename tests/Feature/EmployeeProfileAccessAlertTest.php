<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileAccessAlertTest extends TestCase
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

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Http::preventStrayRequests();
        Notification::fake();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->unverified()->create([
            'role' => 'staff',
            'status' => true,
            'name' => 'Access Alert Synthetic Employee',
            'email' => 'access-alert@example.invalid',
        ]);
    }

    #[DataProvider('verificationAndHostStates')]
    public function test_only_unverified_staff_get_the_access_hint_on_both_native_hosts(bool $verified, bool $active, bool $embedded): void
    {
        $this->employee->forceFill(['email_verified_at' => $verified ? now() : null, 'status' => $active])->save();
        $before = $this->employee->fresh()->getAttributes();

        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $alerts = $xpath->query('//*[@data-account-invitation]');

        $this->assertCount($verified ? 0 : 1, $alerts);
        if ($verified) {
            $component->assertDontSee('Mitarbeiter einladen')->assertDontSee('Zugang eingerichtet.');
        } else {
            /** @var DOMElement $alert */
            $alert = $alerts->item(0);
            $this->assertSame('alert', $alert->getAttribute('role'));
            $this->assertSame('Mitarbeiterzugang', $alert->getAttribute('aria-label'));
            $this->assertSame('employee-profile-'.$this->employee->id.'-account-invitation', $alert->getAttribute('wire:key'));
            $this->assertTrue($alert->hasAttribute('wire:ignore.self'));
            $this->assertFalse($alert->hasAttribute('wire:ignore'));
            $this->assertFalse($alert->hasAttribute('x-cloak'), 'The initial visible alert must reserve its real height before Alpine starts.');
            $component->assertSee($active ? 'E-Mail noch nicht bestätigt.' : 'Konto inaktiv – bitte zuerst aktivieren.');
            $actions = $xpath->query('.//button[contains(concat(" ",normalize-space(@class)," ")," rt-ui-alert__action ")]', $alert);
            $this->assertCount(1, $actions);
            $this->assertSame(! $active, $actions->item(0)->hasAttribute('disabled'));
            $this->assertStringContainsString('sendAccountInvitation', $actions->item(0)->getAttribute('x-on:click.prevent'));
            $this->assertStringContainsString($this->employee->email, $actions->item(0)->getAttribute('x-on:click.prevent'));
        }

        $this->assertSame($before, $this->employee->fresh()->getAttributes());
        $this->assertNoAccessSideEffects();
    }

    public static function verificationAndHostStates(): array
    {
        $states = [];
        foreach ([false, true] as $embedded) {
            foreach ([false, true] as $verified) {
                foreach ([false, true] as $active) {
                    $states[($embedded ? 'embedded' : 'standalone').' '.($verified ? 'verified' : 'unverified').' '.($active ? 'active' : 'inactive')] = [$verified, $active, $embedded];
                }
            }
        }

        return $states;
    }

    #[DataProvider('nativeHosts')]
    public function test_next_native_rerender_uses_current_verification_without_changing_the_account(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => $embedded]);
        $component->assertSee('Mitarbeiter einladen');

        foreach ([[true, true], [true, false], [false, false], [false, true]] as [$verified, $active]) {
            $this->employee->forceFill(['email_verified_at' => $verified ? now() : null, 'status' => $active])->save();
            $before = $this->employee->fresh()->getAttributes();
            $component->call('setProfileTab', 'userDetails')->assertSuccessful();
            $this->assertCount($verified ? 0 : 1, $this->xpath($component->html())->query('//*[@data-account-invitation]'));
            $this->assertSame($before, $this->employee->fresh()->getAttributes());
        }

        $this->assertNoAccessSideEffects();
    }

    public static function nativeHosts(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    #[DataProvider('otherRoles')]
    public function test_unverified_non_staff_accounts_do_not_receive_employee_invitations(string $role): void
    {
        $this->employee->forceFill(['role' => $role])->save();
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('data-account-invitation', false)->assertDontSee('Mitarbeiter einladen');
        $this->assertNoAccessSideEffects();
    }

    public static function otherRoles(): array
    {
        return ['admin' => ['admin'], 'superadmin' => ['superadmin'], 'editor' => ['editor']];
    }

    public function test_management_visibility_remains_gated_by_existing_employee_permission(): void
    {
        $manager = $this->manager(true);
        $component = Livewire::actingAs($manager)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSee('data-account-invitation', false);
        $this->employee->forceFill(['email_verified_at' => now()])->save();
        $component->call('setProfileTab', 'userDetails')->assertSuccessful()->assertDontSee('data-account-invitation', false);

        Livewire::actingAs($this->manager(false))->test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
        Livewire::actingAs($this->employee->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
        $this->assertNoAccessSideEffects();
    }

    public function test_inactive_management_actor_does_not_gain_an_invitation_hint(): void
    {
        $this->admin->forceFill(['status' => false])->save();
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('data-account-invitation', false);
        $this->assertNoAccessSideEffects();
    }

    public function test_shared_alert_stays_accessible_and_visible_at_first_paint_without_changing_dismissal(): void
    {
        $html = Blade::render('<x-ui.feedback.alert type="warning" :inline="true" aria-label="Synthetic access hint">Fixture text</x-ui.feedback.alert>');
        $xpath = $this->xpath($html);
        $alerts = $xpath->query('//*[@role="alert"]');
        $this->assertCount(1, $alerts);
        $alert = $alerts->item(0);
        $this->assertSame('visible', $alert->getAttribute('x-show.important'));
        $this->assertFalse($alert->hasAttribute('x-cloak'));
        $this->assertTrue($alert->hasAttribute('wire:ignore.self'));
        $this->assertFalse($alert->hasAttribute('wire:ignore'));
        $this->assertStringNotContainsString('display:none', $alert->getAttribute('style'));
        $close = $xpath->query('.//button[@aria-label="Hinweis schließen"]', $alert);
        $this->assertCount(1, $close);
        $this->assertSame('button', $close->item(0)->getAttribute('type'));
        $this->assertSame('visible = false', $close->item(0)->getAttribute('x-on:click'));
        $this->assertStringNotContainsString('x-transition', $html);
        $this->assertStringContainsString('motion-reduce:animate-none', $html);
        $this->assertStringContainsString('Fixture text', $html);

        $fixed = Blade::render('<x-ui.feedback.alert :dismissible="false">Fixture text</x-ui.feedback.alert>');
        $this->assertCount(0, $this->xpath($fixed)->query('//button[@aria-label="Hinweis schließen"]'));
    }

    private function manager(bool $employeePermission): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['employees.view' => $employeePermission],
        ]);
        $manager->teams()->attach($team);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        return $manager->fresh();
    }

    private function assertNoAccessSideEffects(): void
    {
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseMissing('activity_log', ['description' => 'employee_account_invitation_sent']);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
