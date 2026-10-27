<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Livewire\Admin\UserProfile;
use App\Livewire\Admin\UserProfile\OutlookPreferences;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinAutomaticInsertionPolicy;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinWordingPolicy;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class EmployeeOutlookPreferencesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $target;

    private EmployeeIdentityAccount $identity;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.entra.tenant_id' => '11111111-1111-4111-8111-111111111111']);
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('principal');
            $table->string('email')->nullable();
            $table->string('lifecycle_status');
            $table->timestamps();
        });
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true, 'email_verified_at' => now()]);
        $this->target = User::factory()->create(['role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        $this->identity = EmployeeIdentityAccount::create([
            'user_id' => $this->target->id, 'provider' => AccountProvider::Microsoft365,
            'external_id' => '33333333-3333-4333-8333-333333333333',
            'tenant_id' => config('outlook_addin.entra.tenant_id'),
            'principal' => 'target@company.test', 'email' => 'target@company.test', 'lifecycle_status' => 'active',
        ]);
        $this->actingAs($this->actor);
    }

    private function preferencesComponent(): Testable
    {
        return Livewire::test(OutlookPreferences::class, ['userId' => $this->target->id]);
    }

    private function manager(array $abilities): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true, 'email_verified_at' => now()]);
        $team = Team::forceCreate(['user_id' => $this->actor->id, 'name' => 'Verwaltung', 'personal_team' => false,
            'rbac_permissions' => array_fill_keys($abilities, true)]);
        $manager->teams()->attach($team);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        return $manager->fresh();
    }

    public function test_target_controls_preserve_actor_other_settings_bindings_and_audit_the_actual_operator(): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->actor->id, ['enabled' => false, 'keep' => 'actor']);
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->target->id, ['enabled' => true, 'keep' => ['exact' => true]]);
        $actorBefore = Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->actor->id);
        $usersBefore = DB::table('users')->orderBy('id')->get()->toArray();
        $linksBefore = DB::table('employee_identity_accounts')->orderBy('id')->get()->toArray();
        $component = $this->preferencesComponent()->assertSet('available', true)->assertSet('wordingAvailable', true)
            ->assertSet('instancePrefix', fn (string $prefix): bool => str_starts_with($prefix, 'employee-outlook-'.$this->actor->id.'-'.$this->target->id.'-'));
        self::assertNotSame($component->get('instancePrefix'), $this->preferencesComponent()->get('instancePrefix'));
        $component->call('updatePreference', 'replies', 'signature', false)->assertHasNoErrors()
            ->assertSet('preferences.replies.signature', false);
        $component->set('greeting', 'Hallo,')->set('farewell', 'Beste Grüße,')->call('saveWording')->assertHasNoErrors();
        $stored = Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->target->id);
        self::assertFalse($stored['replies']['signature']);
        self::assertSame(['exact' => true], $stored['keep']);
        self::assertSame($actorBefore, Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->actor->id));
        self::assertSame('Hallo,', Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->target->id)['greeting']);
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->actor->id));
        self::assertEquals($usersBefore, DB::table('users')->orderBy('id')->get()->toArray());
        self::assertEquals($linksBefore, DB::table('employee_identity_accounts')->orderBy('id')->get()->toArray());
        foreach (DB::table('activity_log')->get() as $entry) {
            self::assertSame($this->actor->id, $entry->causer_id);
            self::assertSame($this->target->id, $entry->subject_id);
            self::assertSame($this->target->id, json_decode($entry->properties, true)['employee_id']);
        }
        self::assertSame(2, DB::table('activity_log')->count());
    }

    public function test_unbound_target_wording_remains_available_without_creating_a_link_or_automation_preferences(): void
    {
        $this->identity->delete();
        $this->preferencesComponent()->assertSet('available', false)->assertSet('wordingAvailable', true)
            ->set('greeting', 'Guten Morgen,')->call('saveWording')->assertHasNoErrors();
        self::assertSame(0, EmployeeIdentityAccount::count());
        self::assertSame(1, Setting::count());
        self::assertSame('Guten Morgen,', Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->target->id)['greeting']);
    }

    public function test_managed_context_selection_changes_only_the_target_template_boolean_and_preserves_the_wording_draft(): void
    {
        $component = $this->preferencesComponent()->assertSet('choicesAvailable', true)
            ->assertSet('choiceSelections', ['newMessages' => 'default', 'replies' => 'default'])
            ->set('greeting', 'Ungespeicherter Text,');
        $component->call('updateTemplateChoice', 'newMessages', 'none')->assertHasNoErrors()
            ->assertSet('choiceSelections.newMessages', 'none')->assertSet('choiceSelections.replies', 'default')
            ->assertSet('preferences.newMessages.template', false)->assertSet('preferences.replies.template', true)
            ->assertSet('preferences.newMessages.signature', true)->assertSet('preferences.replies.signature', true)
            ->assertSet('greeting', 'Ungespeicherter Text,')
            ->call('reloadPreferences')->assertSet('choiceSelections.newMessages', 'none')
            ->assertSet('greeting', 'Ungespeicherter Text,');
        self::assertNull(Setting::getValueUncached('outlook_addin', 'automatic_insertion_user_'.$this->actor->id));
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_choices_user_'.$this->actor->id));
        self::assertNull(Setting::getValueUncached('outlook_addin', 'template_wording_user_'.$this->target->id));
        $audits = DB::table('activity_log')->get();
        self::assertCount(2, $audits);
        foreach ($audits as $entry) {
            self::assertSame($this->actor->id, $entry->causer_id);
            self::assertSame($this->target->id, $entry->subject_id);
        }
    }

    #[DataProvider('writerContexts')]
    public function test_every_writer_preserves_unknown_nested_metadata_without_exposing_it_as_a_control(string $context): void
    {
        $policy = app(OutlookAddinAutomaticInsertionPolicy::class);
        $key = 'automatic_insertion_user_'.$this->target->id;
        $metadata = ['opaque' => ['nested' => [false, 0, null, 'keep']]];
        Setting::setValue('outlook_addin', $key, [
            'schema' => 2, 'newMessages' => ['signature' => true, 'template' => true, 'futureNested' => $metadata],
            'replies' => ['signature' => true, 'template' => true, 'otherMetadata' => $metadata], 'topLevel' => $metadata,
        ]);
        $preferences = $policy->preferencesForUser($this->target);
        self::assertSame(['signature', 'template'], array_keys($preferences['newMessages']));
        self::assertSame(['signature', 'template'], array_keys($preferences['replies']));
        if ($context === 'managed') {
            $next = $policy->updateForManagedUser($this->actor, $this->target, 'replies', 'signature', false, $preferences['revision']);
        } elseif ($context === 'session') {
            $this->actingAs($this->target);
            $next = $policy->updateForSession($this->target, 'replies', 'signature', false, $preferences['revision']);
        } else {
            $verified = new VerifiedEntraIdentity($this->identity->tenant_id, $this->identity->external_id, $this->identity->principal, 'Synthetic');
            $next = $policy->updateForIdentity($this->target, $verified, [
                'allowedSenderAddresses' => [$this->identity->principal],
                'mailboxAddress' => $this->identity->principal, 'senderAddress' => $this->identity->principal,
            ], 'replies', 'signature', false, $preferences['revision']);
        }
        $stored = Setting::getValueUncached('outlook_addin', $key);
        self::assertSame($metadata, $stored['newMessages']['futureNested']);
        self::assertSame($metadata, $stored['replies']['otherMetadata']);
        self::assertSame($metadata, $stored['topLevel']);
        self::assertFalse($next['replies']['signature']);
        self::assertArrayNotHasKey('futureNested', $next['newMessages']);
        self::assertArrayNotHasKey('otherMetadata', $next['replies']);
        self::assertSame(1, DB::table('activity_log')->count());
    }

    public static function writerContexts(): array
    {
        return ['employee management' => ['managed'], 'own profile' => ['session'], 'verified NAA' => ['identity']];
    }

    public function test_delegated_management_requires_both_existing_permissions_and_cannot_edit_admin_accounts(): void
    {
        foreach ([['employees.view'], ['users.edit']] as $abilities) {
            $this->actingAs($this->manager($abilities));
            $this->preferencesComponent()->assertForbidden();
        }
        $manager = $this->manager(['employees.view', 'users.edit']);
        $this->actingAs($manager);
        $this->preferencesComponent()->assertSet('available', true)->call('updatePreference', 'newMessages', 'template', false)->assertHasNoErrors();
        self::assertSame($manager->id, DB::table('activity_log')->first()->causer_id);
        $this->target->update(['role' => 'admin']);
        $this->preferencesComponent()->assertForbidden();
    }

    public function test_target_id_is_locked_and_forged_actor_cannot_use_managed_policy(): void
    {
        $component = $this->preferencesComponent();
        try {
            $component->set('userId', $this->actor->id);
            self::fail('Locked target must reject client mutation.');
        } catch (CannotUpdateLockedPropertyException) {
            self::assertSame(0, Setting::count());
        }
        $impostor = User::factory()->create(['role' => 'admin', 'status' => true, 'email_verified_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(OutlookAddinWordingPolicy::class)->readForManagedUser($impostor, $this->target);
    }

    public function test_revoked_permissions_and_target_admin_promotion_are_checked_again_for_each_action(): void
    {
        $manager = $this->manager(['employees.view', 'users.edit']);
        $this->actingAs($manager);
        $component = $this->preferencesComponent();
        $manager->currentTeam->update(['rbac_permissions' => ['employees.view' => true]]);
        $component->call('updatePreference', 'newMessages', 'template', false)->assertForbidden();
        self::assertSame(0, Setting::count());
        $manager->currentTeam->update(['rbac_permissions' => ['employees.view' => true, 'users.edit' => true]]);
        $component = $this->preferencesComponent()->set('greeting', 'Hallo,');
        $this->target->update(['role' => 'admin']);
        $component->call('saveWording')->assertForbidden();
        self::assertSame(0, Setting::count());
    }

    #[DataProvider('revokedStates')]
    public function test_transaction_refresh_rejects_stale_actor_or_target(string $person, array $change): void
    {
        $policy = app(OutlookAddinWordingPolicy::class);
        $revision = $policy->readForManagedUser($this->actor, $this->target)['revision'];
        Event::listen(TransactionBeginning::class, function () use ($person, $change): void {
            Event::forget(TransactionBeginning::class);
            User::query()->whereKey($person === 'actor' ? $this->actor->id : $this->target->id)->update($change);
        });
        try {
            $policy->updateForManagedUser($this->actor, $this->target, 'Hallo,', 'Grüße,', $revision);
            self::fail('Fresh authorization must reject revocation.');
        } catch (OutlookAddinException|AuthorizationException) {
            self::assertSame(0, Setting::count());
            self::assertSame(0, DB::table('activity_log')->count());
        }
    }

    public static function revokedStates(): array
    {
        return ['actor inactive' => ['actor', ['status' => false]], 'actor unverified' => ['actor', ['email_verified_at' => null]],
            'target inactive' => ['target', ['status' => false]], 'target unverified' => ['target', ['email_verified_at' => null]],
            'target nonemployee' => ['target', ['role' => 'user']]];
    }

    public function test_conflicting_revisions_do_not_overwrite_and_wording_draft_requires_explicit_reload(): void
    {
        $component = $this->preferencesComponent();
        $automatic = app(OutlookAddinAutomaticInsertionPolicy::class);
        $automatic->updateForManagedUser($this->actor, $this->target, 'newMessages', 'signature', false, $component->get('revision'));
        $component->call('updatePreference', 'replies', 'signature', false)->assertSet('preferences.replies.signature', true)
            ->assertSee('inzwischen geändert');
        $wording = app(OutlookAddinWordingPolicy::class);
        $wording->updateForManagedUser($this->actor, $this->target, 'Andere Anrede,', 'Anderer Gruß,', $component->get('wordingRevision'));
        $component->set('greeting', 'Eigener Entwurf,')->call('saveWording')->assertSet('greeting', 'Eigener Entwurf,')
            ->assertSee('Eingabe bleibt erhalten')->call('reloadWording')->assertSet('greeting', 'Andere Anrede,');
        self::assertSame(2, DB::table('activity_log')->count());
    }

    #[DataProvider('invalidBindings')]
    public function test_fresh_microsoft_binding_checks_still_apply_to_managed_automation(string $case): void
    {
        $component = $this->preferencesComponent();
        if (in_array($case, ['duplicate', 'duplicate_object_other_user'], true)) {
            EmployeeIdentityAccount::create(array_replace($this->identity->getAttributes(), [
                'id' => null, 'principal' => 'duplicate@company.test',
                'user_id' => $case === 'duplicate' ? $this->target->id : $this->actor->id,
            ]));
        } else {
            $this->identity->update(match ($case) {
                'rebound' => ['user_id' => $this->actor->id], 'tenant' => ['tenant_id' => '44444444-4444-4444-8444-444444444444'],
                default => ['lifecycle_status' => 'inactive'],
            });
        }
        $component->call('updatePreference', 'replies', 'template', false)->assertSet('available', false);
        self::assertSame(0, Setting::count());
        self::assertSame(0, DB::table('activity_log')->count());
    }

    public static function invalidBindings(): array
    {
        return array_map(static fn (string $case): array => [$case], ['duplicate', 'duplicate_object_other_user', 'rebound', 'tenant', 'inactive']);
    }

    public function test_existing_employee_profile_renders_lazy_outlook_tab_only_for_account_editors(): void
    {
        foreach (['2026_07_22_000002_create_employee_document_requirements_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Livewire::test(UserProfile::class, ['userId' => $this->target->id])
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => isset($tabs['outlook']))
            ->assertDontSee('employee-outlook-'.$this->actor->id.'-'.$this->target->id)
            ->call('setProfileTab', 'outlook')->assertSee('Outlook-Einstellungen')
            ->assertSee('employee-outlook-'.$this->actor->id.'-'.$this->target->id);
        $this->actingAs($this->manager(['employees.view']));
        Livewire::test(UserProfile::class, ['userId' => $this->target->id])
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['outlook']))
            ->call('setProfileTab', 'outlook')->assertForbidden();
    }
}
