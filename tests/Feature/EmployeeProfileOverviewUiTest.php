<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\CustomerPortalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use App\Services\Operations\PersonnelScopeService;
use App\Support\Rbac\RbacCatalog;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileOverviewUiTest extends TestCase
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
            '2026_08_17_070000_create_device_management_tables.php',
            '2026_10_11_120200_create_employee_location_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'name' => 'Profile Overview Fixture',
            'email' => 'profile-overview@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_overview_keeps_shared_manual_tabs_and_existing_authorized_sections(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'userDetails');

        $component->assertViewHas('employeeProfileTabs', function (array $tabs): bool {
            $existing = [
                'userDetails', 'userNotes', 'userFiles', 'userMessages', 'devices',
                'masterData', 'documents', 'compensation', 'qualifications', 'training',
                'signatures', 'emergency', 'workflows', 'development', 'sickness', 'tasks',
                'accounts', 'absences', 'workModel', 'vacationPolicy', 'ruleAssignments',
                'planReviews', 'responsibilities',
            ];

            return array_diff($existing, array_keys($tabs)) === []
                && isset($tabs['locations'])
                && $tabs['userDetails']['label'] === 'Übersicht';
        });

        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-profile-shell]'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-overview]'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]'));
        $tablist = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]')->item(0);
        foreach (['arrow-right', 'arrow-left', 'home', 'end'] as $key) {
            $this->assertNotSame('', $tablist->getAttribute('x-on:keydown.'.$key.'.prevent'));
        }
        foreach ($xpath->query('//*[@data-profile-navigation]//*[@role="tab"]') as $tab) {
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertStringContainsString('setProfileTab', $tab->getAttribute('x-on:click'));
            $this->assertFalse($tab->hasAttribute('x-on:focus'));
        }
        $this->assertTabRelationship($component->html(), 'userDetails');
        $this->assertSame([], $this->locationChildren($component->html()));
        $this->assertDatabaseCount('file_pools', 0);
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    public function test_overview_preserves_existing_native_inline_ids_autosave_and_contact_values(): void
    {
        Profile::create([
            'user_id' => $this->employee->id,
            'phone' => '+49 000 111',
            'mobile' => '+49 000 222',
            'street' => 'Fixture Street 10',
            'postal_code' => '04109',
            'city' => 'Leipzig',
            'country' => 'DE',
            'birth_date' => '1991-05-12',
            'personnel_nr' => 'FIXTURE-PERSONNEL-42',
        ]);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSee('Profile Overview Fixture')->assertSee('profile-overview@example.invalid')
            ->assertSee('+49 000 111')->assertSee('+49 000 222')->assertSee('Fixture Street 10')
            ->assertSee('04109')->assertSee('Leipzig')->assertSee('FIXTURE-PERSONNEL-42');
        $xpath = $this->xpath($component->html());

        $this->assertCount(1, $xpath->query('//*[@data-autosave-scope]'));
        foreach ([
            'employee-header-name' => 'name',
            'employee-header-email' => 'email',
            'employee-details-name' => 'name',
            'employee-details-birth-date' => 'birth_date',
            'employee-details-personnel-nr' => 'personnel_nr',
            'employee-contact-email' => 'email',
            'employee-contact-phone' => 'phone',
            'employee-contact-mobile' => 'mobile',
            'employee-contact-street' => 'street',
            'employee-contact-postal_code' => 'postal_code',
            'employee-contact-city' => 'city',
            'employee-contact-country' => 'country',
        ] as $id => $field) {
            $inputs = $xpath->query('//*[@id="'.$id.'"]');
            $this->assertCount(1, $inputs, $id);
            $this->assertSame('inlineValues.'.$field, $inputs->item(0)->getAttribute('wire:model'));
            $triggers = $xpath->query('//*[@for="'.$id.'" and @data-native-inline-edit-trigger]');
            $this->assertCount(1, $triggers, $id.' native trigger');
            $this->assertNotSame('', $triggers->item(0)->getAttribute('x-on:keydown.enter.prevent'));
            $this->assertCount(1, $xpath->query('//*[@data-inline-edit-field="'.$field.'" and @data-autosave-field-id="'.$id.'"]'));
        }

        $source = file_get_contents(resource_path('views/livewire/admin/user-profile.blade.php'));
        $this->assertStringContainsString('<x-ui.autosave-status', $source);
        $this->assertStringContainsString('event="employee-profile-field-saved"', $source);
        $this->assertStringContainsString('target="savePendingInlineChanges"', $source);
        $this->assertStringContainsString('<x-operations.panel.tabs', $source);
    }

    public function test_overview_never_invents_working_time_and_does_not_read_location_records(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
                ->assertSuccessful()->assertSet('profileTab', 'userDetails')
                ->assertViewHas('profile', null)
                ->assertSet('inlineValues.weekly_working_hours', null);
            $this->assertCount(0, array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:from|join)\s+["`]?\b(?:employee_location_(?:points|consents|consent_events)|mobile_location_(?:installations|codes))\b/i',
                $query['query'],
            )));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([], $this->locationChildren($component->html()));
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertDatabaseCount('employee_location_points', 0);
    }

    #[DataProvider('workingTimeSummaries')]
    public function test_working_time_summary_preserves_quarter_hours_zero_and_missing_values(?string $stored, ?string $display): void
    {
        Profile::create(['user_id' => $this->employee->id, 'weekly_working_hours' => $stored]);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $summaries = $this->xpath($component->html())->query('//*[@data-inline-edit-field="weekly_working_hours"]//*[@data-inline-edit-display]');
        $this->assertCount(1, $summaries);
        $text = trim(preg_replace('/\s+/u', ' ', $summaries->item(0)->textContent));
        $this->assertSame($display ?? __('app.not_set'), $text);
    }

    public static function workingTimeSummaries(): array
    {
        return [
            'quarter hours stay exact' => ['37.25', '37,25 h'],
            'whole hours stay compact' => ['40.00', '40 h'],
            'explicit zero is not missing' => ['0.00', '0 h'],
            'missing is not invented' => [null, null],
        ];
    }

    public function test_overview_master_data_summary_and_edit_controls_follow_target_scope(): void
    {
        Profile::create([
            'user_id' => $this->employee->id,
            'position' => 'CONFIDENTIAL-FIXTURE-POSITION',
            'personnel_nr' => 'CONFIDENTIAL-FIXTURE-NR',
            'weekly_working_hours' => '37.25',
            'entry_date' => '2026-09-01',
            'iban' => 'CONFIDENTIAL-FIXTURE-IBAN',
        ]);
        $manager = $this->actor('staff', 'Verwaltung', ['employees.view']);
        $component = Livewire::actingAs($manager)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('CONFIDENTIAL-FIXTURE-POSITION')
            ->assertDontSee('CONFIDENTIAL-FIXTURE-NR')->assertDontSee('37.25')
            ->assertDontSee('CONFIDENTIAL-FIXTURE-IBAN')
            ->assertViewHas('canViewMasterData', false)->assertViewHas('canEditEmployee', false);
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-native-inline-edit-trigger]'));

        $abilities = ['employees.view', 'employees.master-data.view'];
        $reader = $this->actor('staff', 'Verwaltung', $abilities);
        app(PersonnelScopeService::class)->assign($this->employee, [
            'responsible_user_id' => $reader->id,
            'starts_on' => '2026-01-01',
            'abilities' => ['employees.master-data.view'],
        ], $this->admin);
        $readOnly = Livewire::actingAs($reader)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSee('CONFIDENTIAL-FIXTURE-POSITION')->assertSee('CONFIDENTIAL-FIXTURE-NR')
            ->assertDontSee('CONFIDENTIAL-FIXTURE-IBAN')
            ->assertViewHas('canViewMasterData', true)->assertViewHas('canEditMasterData', false);
        $this->assertCount(0, $this->xpath($readOnly->html())->query('//*[@data-native-inline-edit-trigger]'));
    }

    public function test_pending_valid_inline_values_still_save_before_tab_change(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'phone' => '+49 000 111']);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.phone', '+49 000 333')
            ->call('setProfileTab', 'masterData')->assertHasNoErrors()
            ->assertSet('profileTab', 'masterData')->assertSet('dirtyInlineFields', [])
            ->assertDispatched('employee-profile-field-saved');
        $this->assertSame('+49 000 333', Profile::firstWhere('user_id', $this->employee->id)->phone);
        $this->assertTabRelationship($component->html(), 'masterData');
    }

    public function test_invalid_inline_value_prevents_location_tab_and_does_not_mount_history(): void
    {
        $email = $this->employee->email;
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.email', 'not-an-email')
            ->call('setProfileTab', 'locations')->assertHasErrors('inlineValues.email')
            ->assertSet('profileTab', 'userDetails')->assertSet('dirtyInlineFields', ['email']);
        $this->assertSame($email, $this->employee->fresh()->email);
        $this->assertSame([], $this->locationChildren($component->html()));
    }

    public function test_admin_location_history_is_a_lazy_independent_employee_component(): void
    {
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Administratoren',
            'personal_team' => false,
            'rbac_permissions' => ['operations.locations.view' => true],
        ]);
        $this->admin->teams()->attach($team->id);
        $this->admin->forceFill(['current_team_id' => $team->id])->save();
        $component = Livewire::actingAs($this->admin->fresh())->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->assertSame([], $this->locationChildren($component->html()));

        $component->call('setProfileTab', 'locations')->assertSuccessful()->assertSet('profileTab', 'locations');
        $children = $this->locationChildren($component->html());
        $this->assertCount(1, $children);
        $this->assertSame($this->employee->id, $children[0]['data']['userId']);
        $this->assertTabRelationship($component->html(), 'locations');
        $component->call('setProfileTab', 'userDetails')->assertSet('profileTab', 'userDetails');
        $this->assertSame([], $this->locationChildren($component->html()));
    }

    #[DataProvider('nonAdminReaders')]
    public function test_location_tab_rejects_non_admins_even_with_broad_team_permissions(string $role, string $teamName, bool $profileAllowed): void
    {
        $actor = $this->actor($role, $teamName, RbacCatalog::allPermissions());
        $component = Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id]);
        if (! $profileAllowed) {
            $component->assertForbidden();

            return;
        }

        $component->assertSuccessful()
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['locations']));
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-panel-tab="locations"]'));
        $this->assertSame([], $this->locationChildren($component->html()));
        $component->call('setProfileTab', 'locations')->assertForbidden();
    }

    public static function nonAdminReaders(): array
    {
        return [
            'management with all permissions' => ['staff', 'Verwaltung', true],
            'administration team is not global admin' => ['staff', 'Administratoren', true],
            'regular staff with all permissions' => ['staff', 'Mitarbeiter', false],
        ];
    }

    public function test_customer_portal_principal_cannot_mount_an_internal_employee_profile(): void
    {
        (require database_path('migrations/2026_10_06_110000_create_customer_portal_access.php'))->up();
        $identity = CustomerPortalIdentity::create([
            'name' => 'Portal Profile Fixture',
            'email' => 'portal-profile@example.invalid',
            'password' => 'FixturePortalPassword123!',
            'active' => true,
            'revision' => 1,
        ]);
        // Internal-looking values never turn the separate customer principal into a User.
        $identity->setAttribute('role', 'admin');
        $identity->setAttribute('status', true);

        Livewire::actingAs($identity)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertForbidden();
    }

    public function test_inactive_admin_has_no_location_tab_or_history(): void
    {
        $this->admin->forceFill(['status' => false])->save();
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['locations']));
        $this->assertSame([], $this->locationChildren($component->html()));
        $component->call('setProfileTab', 'locations')->assertForbidden();
    }

    public function test_location_tab_is_not_offered_for_non_employee_target(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->admin->id])
            ->assertSuccessful()
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['locations']));
        $this->assertSame([], $this->locationChildren($component->html()));
        $component->call('setProfileTab', 'locations')->assertForbidden();
    }

    public function test_profile_tab_cannot_be_switched_by_tampering_with_locked_property(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('profileTab', 'locations');
    }

    public function test_profile_employee_context_remains_locked(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('userId', $this->admin->id);
    }

    private function actor(string $role, string $teamName, array $abilities): User
    {
        $actor = User::factory()->create(['role' => $role, 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => $teamName,
            'personal_team' => false,
            'rbac_permissions' => array_fill_keys($abilities, true),
        ]);
        $actor->teams()->attach($team->id);
        $actor->forceFill(['current_team_id' => $team->id])->save();

        return $actor->fresh();
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

    private function assertTabRelationship(string $html, string $key): void
    {
        $xpath = $this->xpath($html);
        $selected = $xpath->query('//*[@role="tab" and @aria-selected="true"]');
        $this->assertCount(1, $selected);
        $tab = $selected->item(0);
        $this->assertSame($key, $tab->getAttribute('data-panel-tab'));
        $this->assertSame('0', $tab->getAttribute('tabindex'));
        $panels = $xpath->query('//*[@id="'.$tab->getAttribute('aria-controls').'"]');
        $this->assertCount(1, $panels);
        $this->assertSame('tabpanel', $panels->item(0)->getAttribute('role'));
        $this->assertSame($tab->getAttribute('id'), $panels->item(0)->getAttribute('aria-labelledby'));
    }

    private function locationChildren(string $html): array
    {
        $children = [];
        foreach ($this->xpath($html)->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
            if ($snapshot['memo']['name'] === 'profile.employee-location-history') {
                $children[] = $snapshot;
            }
        }

        return $children;
    }
}
