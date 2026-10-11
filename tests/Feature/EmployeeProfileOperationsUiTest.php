<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Livewire\Profile\EmployeeOperations;
use App\Models\CustomerPortalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use App\Support\Rbac\RbacCatalog;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileOperationsUiTest extends TestCase
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
            '2026_10_11_120000_add_confirmed_routing_locations.php',
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
            'name' => 'Operations Profile Fixture',
            'email' => 'operations-profile@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_betrieb_is_first_and_default_with_all_existing_sections_preserved(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'operationsOverview');

        $component->assertViewHas('employeeProfileTabs', function (array $tabs): bool {
            $existing = [
                'userDetails', 'userNotes', 'userFiles', 'userMessages', 'devices',
                'masterData', 'documents', 'compensation', 'qualifications', 'training',
                'signatures', 'emergency', 'workflows', 'development', 'sickness', 'tasks',
                'accounts', 'absences', 'workModel', 'vacationPolicy', 'ruleAssignments',
                'planReviews', 'responsibilities',
            ];

            return array_slice(array_keys($tabs), 0, 3) === ['operationsOverview', 'shifts', 'locations']
                && array_diff($existing, array_keys($tabs)) === []
                && $tabs['operationsOverview']['label'] === 'Übersicht'
                && $tabs['shifts']['label'] === 'Schichten'
                && $tabs['locations']['label'] === 'Standorte'
                && collect($tabs)->only(['operationsOverview', 'shifts', 'locations'])->every(fn ($tab) => $tab['group'] === 'Betrieb');
        });
        $xpath = $this->xpath($component->html());
        $groups = $xpath->query('//*[@data-profile-navigation]//*[@data-profile-group]');
        $this->assertSame(['Betrieb', 'Profil & Stammdaten', 'Personalakte', 'Entwicklung & Aufgaben', 'Arbeitszeit & Regeln'], array_map(
            static fn ($group): string => $group->getAttribute('data-profile-group'),
            iterator_to_array($groups),
        ));
        $this->assertSame('page', $groups->item(0)->getAttribute('aria-current'));
        $count = $xpath->query('.//*[contains(@class,"employee-profile__group-count") and @aria-hidden="true"]', $groups->item(0));
        $this->assertCount(1, $count);
        $this->assertSame('3', trim($count->item(0)->textContent));
        $this->assertSame(['operationsOverview', 'shifts', 'locations'], array_map(
            static fn ($tab): string => $tab->getAttribute('data-panel-tab'),
            iterator_to_array($xpath->query('//*[@data-profile-navigation]//*[@role="tab"]')),
        ));
        $this->assertNativeTabs($component->html(), 'operationsOverview');
        $this->assertCount(0, $xpath->query('//*[@data-profile-overview]'));
        $this->assertDatabaseCount('file_pools', 0);
        Http::assertNothingSent();
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    public function test_operations_module_mounts_only_for_its_active_tab_with_locked_employee_context(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $children = $this->children($component->html(), 'profile.employee-operations');
        $this->assertCount(1, $children);
        $this->assertSame($this->employee->id, $children[0]['data']['userId']);
        $this->assertSame('overview', $children[0]['data']['viewMode']);
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));

        $component->call('setProfileTab', 'shifts')->assertSuccessful()->assertSet('profileTab', 'shifts');
        $children = $this->children($component->html(), 'profile.employee-operations');
        $this->assertCount(1, $children);
        $this->assertSame($this->employee->id, $children[0]['data']['userId']);
        $this->assertSame('shifts', $children[0]['data']['viewMode']);
        $this->assertNativeTabs($component->html(), 'shifts');

        $component->call('setProfileTab', 'userDetails')->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-profile-overview]'));
        $this->assertNativeTabs($component->html(), 'userDetails');
    }

    public function test_real_operational_overview_renders_saved_shift_context_without_private_source_payloads(): void
    {
        $shifts = $this->shiftFixtures();
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSee('Current Operations Fixture')
            ->assertSee('Upcoming Operations Fixture 01')->assertSee('Past Operations Fixture')
            ->assertSee('Saved Station Fixture')->assertSee('Synthetic Customer Fixture')
            ->assertSee('Planungsdaten, keine Live-Anwesenheit.')
            ->assertDontSee('PRIVATE-ORDER-FIXTURE')->assertDontSee('PRIVATE-SHIFT-FIXTURE')
            ->assertDontSee('PRIVATE-ASSIGNMENT-FIXTURE')->assertDontSee('PRIVATE-SNAPSHOT-FIXTURE');
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//*[@data-employee-operations]//*[@onerror]'));
        $component->assertDontSee('48.123456')->assertDontSee('11.123456');
        $current = $xpath->query('//*[@data-employee-shift-preview="'.$shifts['current'].'"]');
        $this->assertCount(1, $current);
        $this->assertCount(2, $xpath->query('.//a[contains(@href,"shift='.$shifts['current'].'") and @*[name()="wire:navigate"]]', $current->item(0)));
        $this->assertCount(1, $xpath->query('//*[@data-employee-shift-preview="'.$shifts['past'].'"]'));
        $this->assertCount(1, $xpath->query('//*[@data-employee-operations and @data-operations-view="overview"]'));
        $children = $this->children($component->html(), 'profile.employee-operations');
        $this->assertCount(1, $children);
        foreach (['summary', 'records', 'employeeOperations', 'location_snapshot', 'current', 'next', 'previous'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $children[0]['data']);
        }
        Http::assertNothingSent();
    }

    public function test_real_shift_list_uses_shared_mobile_headers_search_named_pager_and_native_detail_actions(): void
    {
        $this->shiftFixtures();
        $component = Livewire::actingAs($this->admin)->test(EmployeeOperations::class, [
            'userId' => $this->employee->id,
            'viewMode' => 'shifts',
        ])->assertSuccessful()->assertSee('Upcoming Operations Fixture 01')
            ->assertDontSee('Upcoming Operations Fixture 16')->assertDontSee('Past Operations Fixture')
            ->assertDontSee('PRIVATE-ORDER-FIXTURE')->assertDontSee('PRIVATE-SHIFT-FIXTURE')
            ->assertDontSee('PRIVATE-ASSIGNMENT-FIXTURE')->assertDontSee('PRIVATE-SNAPSHOT-FIXTURE');
        $xpath = $this->xpath($component->html());
        $tables = $xpath->query('//*[@data-rt-premium-table and @data-table-mobile-header]');
        $this->assertCount(1, $tables);
        $this->assertSame('employeeShiftsPage', $tables->item(0)->getAttribute('data-list-page-name'));
        $this->assertSame('true', $tables->item(0)->getAttribute('data-list-has-next'));
        $this->assertSame(['Zeitraum', 'Einsatz', 'Plan & Rückmeldung'], array_map(
            static fn ($header): string => trim($header->textContent),
            iterator_to_array($xpath->query('//*[@role="columnheader"]')),
        ));
        $this->assertCount(0, $xpath->query('//*[contains(@class,"rt-table-mobile-sort")]'));
        $this->assertCount(1, $xpath->query('//input[@*[name()="wire:model.live.debounce.300ms"]="search"]'));
        $pagers = $xpath->query('//nav[@data-table-pagination]');
        $this->assertCount(1, $pagers);
        $this->assertSame('employeeShiftsPage', $pagers->item(0)->getAttribute('data-table-page-name'));
        $this->assertCount(1, $xpath->query('//button[contains(@*[name()="wire:click"],"nextPage") and contains(@*[name()="wire:click"],"employeeShiftsPage")]'));
        $this->assertGreaterThan(0, $xpath->query('//*[@*[name()="x-data"] and contains(@*[name()="x-data"],"openShift")]')->length);

        $component->call('gotoPage', 2, 'employeeShiftsPage')->assertSuccessful()
            ->assertSet('paginators.employeeShiftsPage', 2)->assertSee('Upcoming Operations Fixture 16')
            ->assertDontSee('Upcoming Operations Fixture 01');
        $component->call('setPeriod', 'past')->assertSuccessful()->assertSet('paginators.employeeShiftsPage', 1)
            ->assertSee('Past Operations Fixture')->assertDontSee('Upcoming Operations Fixture');
        $component->call('setPeriod', 'all')->call('setSearch', 'Fixture 16')->assertSuccessful()
            ->assertSee('Upcoming Operations Fixture 16')->assertDontSee('Past Operations Fixture')
            ->assertDontSee('Current Operations Fixture');
        Http::assertNothingSent();
    }

    public function test_invalid_saved_schedule_renders_a_neutral_notice_without_empty_time_elements(): void
    {
        $this->shiftFixtures();
        $invalidId = DB::table('shifts')->where('title', 'Upcoming Operations Fixture 16')->value('id');
        DB::table('shifts')->where('id', $invalidId)->update([
            'title' => 'Incomplete Schedule Fixture',
            'ends_at' => '2026-10-01 00:00:00',
        ]);
        $component = Livewire::actingAs($this->admin)->test(EmployeeOperations::class, [
            'userId' => $this->employee->id,
            'viewMode' => 'shifts',
        ])->call('setPeriod', 'all')->call('setSearch', 'Incomplete Schedule Fixture')
            ->assertSuccessful()->assertSee('Incomplete Schedule Fixture')
            ->assertSee('Zeitraum prüfen')->assertSee('Nicht vollständig hinterlegt');
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//*[@data-employee-operations]//time'));
        $links = $xpath->query('//a[contains(@href,"shift='.$invalidId.'")]');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $this->assertStringNotContainsString('from=', $link->getAttribute('href'));
            $this->assertStringNotContainsString('until=', $link->getAttribute('href'));
        }
        Http::assertNothingSent();
    }

    public function test_active_management_with_both_permissions_sees_betrieb_but_never_administrator_locations(): void
    {
        $actor = $this->actor('staff', 'Verwaltung', ['employees.view', 'operations.manage', 'operations.locations.view']);
        $component = Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'operationsOverview')
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => array_keys($tabs) === ['operationsOverview', 'shifts', 'userDetails']);
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//*[@data-panel-tab="locations"]'));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        $component->call('setProfileTab', 'shifts')->assertSuccessful();
        $component->call('setProfileTab', 'locations')->assertForbidden();
    }

    public function test_profile_reader_without_operations_permission_keeps_old_default_and_cannot_mount_operations(): void
    {
        $actor = $this->actor('staff', 'Verwaltung', ['employees.view']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id])
                ->assertSuccessful()->assertSet('profileTab', 'userDetails')
                ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => array_keys($tabs) === ['userDetails']);
            $this->assertNoReadsFrom(['shifts', 'shift_assignments', 'employee_location_points', 'employee_location_consents']);
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        $component->call('setProfileTab', 'shifts')->assertForbidden();
    }

    #[DataProvider('deniedReaders')]
    public function test_operations_permission_alone_or_employee_team_never_grants_management_profile_access(string $teamName, array $permissions): void
    {
        $actor = $this->actor('staff', $teamName, $permissions);
        Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    public static function deniedReaders(): array
    {
        return [
            'management without employee view' => ['Verwaltung', ['operations.manage']],
            'employee with broad permissions' => ['Mitarbeiter', RbacCatalog::allPermissions()],
        ];
    }

    public function test_customer_principal_with_forged_internal_role_cannot_mount_employee_operations(): void
    {
        (require database_path('migrations/2026_10_06_110000_create_customer_portal_access.php'))->up();
        $identity = CustomerPortalIdentity::create([
            'name' => 'Portal Operations Fixture',
            'email' => 'portal-operations@example.invalid',
            'password' => 'FixturePortalPassword123!',
            'active' => true,
            'revision' => 1,
        ]);
        $identity->setAttribute('role', 'superadmin');
        $identity->setAttribute('status', true);
        Livewire::actingAs($identity)->test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    public function test_inactive_admin_keeps_existing_profile_without_operational_or_location_tabs(): void
    {
        $this->admin->forceFill(['status' => false])->save();
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'userDetails')
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['operationsOverview']) && ! isset($tabs['shifts']) && ! isset($tabs['locations']));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        $component->call('setProfileTab', 'operationsOverview')->assertForbidden();
    }

    public function test_non_employee_target_preserves_personal_default_without_operational_group(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->admin->id])
            ->assertSuccessful()->assertSet('profileTab', 'userDetails')
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['operationsOverview']) && ! isset($tabs['shifts']) && ! isset($tabs['locations']));
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-profile-group="Betrieb"]'));
        $component->call('setProfileTab', 'shifts')->assertForbidden();
    }

    public function test_administrator_location_tab_stays_lazy_and_does_not_imply_explicit_gps_access(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
                ->assertSuccessful()->assertSet('profileTab', 'operationsOverview');
            $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
            $this->assertNoReadsFrom(['employee_location_points', 'employee_location_consents', 'employee_location_consent_events', 'mobile_location_installations', 'mobile_location_codes']);

            $component->call('setProfileTab', 'locations')->assertSuccessful()->assertSet('profileTab', 'locations');
            $children = $this->children($component->html(), 'profile.employee-location-history');
            $this->assertCount(1, $children);
            $this->assertSame($this->employee->id, $children[0]['data']['userId']);
            $this->assertArrayNotHasKey('records', $children[0]['data']);
            $this->assertArrayNotHasKey('coordinates', $children[0]['data']);
            $this->assertArrayNotHasKey('locationHistory', $children[0]['data']);
            $this->assertNoReadsFrom(['employee_location_points', 'employee_location_consents', 'employee_location_consent_events', 'mobile_location_installations', 'mobile_location_codes']);
            $this->assertNativeTabs($component->html(), 'locations');
        } finally {
            DB::disableQueryLog();
        }
        $component->call('setProfileTab', 'operationsOverview')->assertSuccessful();
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        Http::assertNothingSent();
    }

    public function test_current_operational_permission_is_rechecked_before_switch_or_refresh(): void
    {
        $actor = $this->actor('staff', 'Verwaltung', ['employees.view', 'operations.manage']);
        $component = Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'operationsOverview');
        $actor->currentTeam->forceFill(['rbac_permissions' => ['employees.view' => true]])->save();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component->call('setProfileTab', 'shifts')->assertForbidden();
            $this->assertNoReadsFrom(['shifts', 'shift_assignments', 'employee_location_points']);
        } finally {
            DB::disableQueryLog();
        }
        Livewire::actingAs($actor->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'userDetails');
    }

    public function test_actor_deactivation_after_mount_stops_operational_tab_without_loading_records(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'operationsOverview');
        User::whereKey($this->admin->id)->update(['status' => false]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component->call('$refresh')->assertForbidden();
            $this->assertNoReadsFrom(['shifts', 'shift_assignments', 'employee_location_points']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_losing_management_audience_after_mount_stops_operational_reads_even_with_unchanged_permissions(): void
    {
        $actor = $this->actor('staff', 'Verwaltung', ['employees.view', 'operations.manage']);
        $component = Livewire::actingAs($actor)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'operationsOverview');
        $actor->currentTeam->forceFill(['name' => 'Mitarbeiter'])->save();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component->call('setProfileTab', 'shifts')->assertForbidden();
            $this->assertNoReadsFrom(['shifts', 'shift_assignments', 'employee_location_points']);
        } finally {
            DB::disableQueryLog();
        }
        Livewire::actingAs($actor->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
    }

    #[DataProvider('lockedContext')]
    public function test_public_context_tampering_cannot_bypass_operation_authorization(string $property, mixed $value): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set($property, $value);
    }

    public static function lockedContext(): array
    {
        return [
            'tab tampering' => ['profileTab', 'shifts'],
            'target tampering' => ['userId', 999999],
        ];
    }

    public function test_unknown_tab_is_rejected_and_invalid_pending_inline_value_keeps_operational_overview(): void
    {
        $email = $this->employee->email;
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.email', 'not-an-email')
            ->call('setProfileTab', 'shifts')->assertHasErrors('inlineValues.email')
            ->assertSet('profileTab', 'operationsOverview')->assertSet('dirtyInlineFields', ['email']);
        $this->assertSame($email, $this->employee->fresh()->email);
        // Livewire keeps the already mounted child as an opaque morph stub
        // after a rejected switch. The native selected tab remains authoritative.
        $this->assertNativeTabs($component->html(), 'operationsOverview');
        $component->call('setProfileTab', 'unexpectedOperation')->assertForbidden();
    }

    public function test_operational_read_and_navigation_do_not_change_business_or_tracking_records(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'SYNTHETIC-OPERATIONS-READ-ONLY']);
        $userBefore = $this->employee->fresh()->getRawOriginal();
        $profileBefore = Profile::firstWhere('user_id', $this->employee->id)->getRawOriginal();
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->call('setProfileTab', 'shifts')->assertSuccessful()
            ->call('setProfileTab', 'userDetails')->assertSuccessful()
            ->call('setProfileTab', 'operationsOverview')->assertSuccessful();
        $this->assertSame($userBefore, $this->employee->fresh()->getRawOriginal());
        $this->assertSame($profileBefore, Profile::firstWhere('user_id', $this->employee->id)->getRawOriginal());
        foreach (['shifts', 'shift_assignments', 'work_time_entries', 'employee_location_points', 'employee_location_consents', 'file_pools'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
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

    /** All writes are synthetic fixtures in the explicitly isolated SQLite schema. */
    private function shiftFixtures(): array
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $customerId = DB::table('customers')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'company_name' => 'Synthetic Customer Fixture',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'title' => 'Synthetic Order Fixture',
            'starts_at' => '2026-10-10 06:00:00',
            'ends_at' => '2026-11-01 18:00:00',
            'notes' => 'PRIVATE-ORDER-FIXTURE',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $create = function (string $title, CarbonImmutable $start, CarbonImmutable $end) use ($orderId, $timestamp): int {
            $shiftId = DB::table('shifts')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'order_id' => $orderId,
                'title' => $title,
                'role_name' => 'Wagenmeister Fixture',
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'timezone' => 'Europe/Berlin',
                'location_name' => 'Original Station Fixture',
                'location_snapshot' => json_encode(['label' => 'Saved Station Fixture <img src=x onerror=fixtureOnly()>', 'latitude' => 48.123456, 'longitude' => 11.123456, 'provider' => 'PRIVATE-SNAPSHOT-FIXTURE'], JSON_THROW_ON_ERROR),
                'status' => 'confirmed',
                'revision' => 1,
                'published_revision' => 1,
                'notes' => 'PRIVATE-SHIFT-FIXTURE',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('shift_assignments')->insert([
                'shift_id' => $shiftId,
                'user_id' => $this->employee->id,
                'status' => 'confirmed',
                'plan_revision' => 1,
                'note' => 'PRIVATE-ASSIGNMENT-FIXTURE',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return $shiftId;
        };
        $now = CarbonImmutable::now('UTC');
        $current = $create('Current Operations Fixture', $now->subHour(), $now->addHour());
        $past = $create('Past Operations Fixture', $now->subDays(2), $now->subDay());
        for ($index = 1; $index <= 16; $index++) {
            $start = $now->addDays($index);
            $create('Upcoming Operations Fixture '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), $start, $start->addHours(2));
        }

        return ['current' => $current, 'past' => $past];
    }

    public function test_operational_mobile_table_overrides_only_its_own_legacy_card_and_utility_layout(): void
    {
        $css = file_get_contents(resource_path('css/employee-profile-shell.css'));
        $this->assertIsString($css);
        // Tailwind's important padding and legacy global full-width mobile cells
        // must not disconnect this opt-in column header from its data cells.
        $this->assertStringContainsString('.employee-profile[data-profile-shell] .employee-operations__table .rt-table-head-cell { padding: 0 !important;', $css);
        $this->assertStringContainsString('.employee-profile[data-profile-shell] .employee-operations__table .rt-table-body { display: block; padding: 0 !important;', $css);
        $this->assertStringContainsString('.employee-profile[data-profile-shell] .employee-operations__table .rt-table-row-grid > .employee-operations__cell { grid-column: auto !important;', $css);
        $this->assertStringContainsString('padding: 12px 56px 12px 12px !important; column-gap: 8px;', $css);
        $this->assertStringContainsString('.employee-operations__table .rt-table-head > .hidden { display: none !important;', $css);
        $this->assertStringContainsString('.employee-operations__controls .rt-multi-toggle__label { font-size: 11px;', $css);
        $this->assertStringContainsString('.employee-operations__controls .rt-multi-toggle__label--full { display: none;', $css);
        $this->assertStringContainsString('.employee-operations__controls .rt-multi-toggle__label--compact { display: inline;', $css);
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

    private function children(string $html, string $alias): array
    {
        $children = [];
        foreach ($this->xpath($html)->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
            if ($snapshot['memo']['name'] === $alias) {
                $children[] = $snapshot;
            }
        }

        return $children;
    }

    private function assertNativeTabs(string $html, string $selectedKey): void
    {
        $xpath = $this->xpath($html);
        $lists = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]');
        $this->assertCount(1, $lists);
        foreach (['arrow-right', 'arrow-left', 'home', 'end'] as $key) {
            $this->assertNotSame('', $lists->item(0)->getAttribute('x-on:keydown.'.$key.'.prevent'));
        }
        foreach ($xpath->query('//*[@data-profile-navigation]//*[@role="tab"]') as $tab) {
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertStringContainsString('setProfileTab', $tab->getAttribute('x-on:click'));
            $this->assertFalse($tab->hasAttribute('x-on:focus'));
            $this->assertSame('employee-profile-'.$this->employee->id.'-tab-'.$tab->getAttribute('data-panel-tab'), $tab->getAttribute('id'));
            $panels = $xpath->query('//*[@id="'.$tab->getAttribute('aria-controls').'"]');
            $this->assertCount(1, $panels);
            $this->assertSame('tabpanel', $panels->item(0)->getAttribute('role'));
            $this->assertSame($tab->getAttribute('id'), $panels->item(0)->getAttribute('aria-labelledby'));
        }
        $selected = $xpath->query('//*[@data-profile-navigation]//*[@role="tab" and @aria-selected="true"]');
        $this->assertCount(1, $selected);
        $this->assertSame($selectedKey, $selected->item(0)->getAttribute('data-panel-tab'));
        $this->assertSame('0', $selected->item(0)->getAttribute('tabindex'));
    }

    private function assertNoReadsFrom(array $tables): void
    {
        $pattern = '/\b(?:from|join)\s+["`]?\b(?:'.implode('|', array_map(static fn (string $table): string => preg_quote($table, '/'), $tables)).')\b/i';
        $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match($pattern, $query['query']));
        $this->assertSame([], array_values($queries));
    }
}
