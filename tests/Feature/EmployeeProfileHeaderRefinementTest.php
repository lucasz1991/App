<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileHeaderRefinementTest extends TestCase
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
            'name' => 'Header Refinement Fixture',
            'email' => 'header-refinement@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_refined_identity_keeps_native_header_edit_and_autosave_contracts(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-profile-shell]'));
        $this->assertCount(1, $xpath->query('//*[@data-autosave-scope]'));
        $this->assertCount(1, $xpath->query('//header[contains(@class,"employee-profile__identity") and @data-profile-identity]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"employee-profile__dossier")]//*[@data-profile-identity]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"employee-profile__dossier")]//*[@data-profile-navigation]'));

        foreach (['employee-header-name' => 'name', 'employee-header-email' => 'email'] as $id => $field) {
            $inputs = $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[@id="'.$id.'"]');
            $this->assertCount(1, $inputs);
            $this->assertSame('inlineValues.'.$field, $inputs->item(0)->getAttribute('wire:model'));
            $triggers = $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[@for="'.$id.'" and @data-native-inline-edit-trigger]');
            $this->assertCount(1, $triggers);
            $this->assertNotSame('', $triggers->item(0)->getAttribute('x-on:keydown.enter.prevent'));
            $this->assertCount(1, $xpath->query('//*[@data-autosave-field-id="'.$id.'" and @data-inline-edit-field="'.$field.'"]'));
        }
        $this->assertSame($this->employee->name, $this->headerNode($xpath, 'employee-profile__name-text')->textContent);
        $this->assertSame($this->employee->email, $this->headerNode($xpath, 'employee-profile__email-text')->textContent);
        $this->assertStringContainsString('#'.$this->employee->id, $this->headerNode($xpath, 'employee-profile__eyebrow')->textContent);
        $this->assertCount(1, $xpath->query('//*[@data-profile-identity]//*[contains(@class,"employee-profile__account")]/h2'));
        foreach (['status', 'member', 'activity'] as $key) {
            $this->assertCount(1, $xpath->query('//*[@data-profile-identity]//*[@data-profile-fact="'.$key.'"]'));
        }
        $this->assertCount(0, $xpath->query('//*[@data-profile-identity]//*[@data-profile-fact="personnel"]'));
        $this->assertSame($this->employee->created_at->format('d.m.Y'), $this->text($xpath->query('//*[@data-profile-fact="member"]/dd')->item(0)));
        $this->assertSame(__('app.never'), $this->text($xpath->query('//*[@data-profile-fact="activity"]/dd')->item(0)));
        $this->assertDatabaseCount('user_profiles', 0);
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    public function test_role_position_and_team_are_deduplicated_without_hiding_distinct_context(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'position' => '  MITARBEITER  ']);
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Mitarbeiter', 'personal_team' => false]);
        $this->employee->forceFill(['current_team_id' => $team->id])->save();

        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $badges = $this->headerBadges($component->html());
        $this->assertSame([__('app.role_staff')], $badges);

        Profile::where('user_id', $this->employee->id)->first()->forceFill(['position' => 'Wagenmeister'])->save();
        $team->update(['name' => 'Region Nord']);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->assertSame([__('app.role_staff'), 'Wagenmeister', 'Region Nord'], $this->headerBadges($component->html()));
    }

    #[DataProvider('presenceCases')]
    public function test_account_state_and_activity_presence_stay_distinct(bool $active, ?int $minutesAgo, bool $online): void
    {
        $this->employee->forceFill(['status' => $active])->save();
        if ($minutesAgo !== null) {
            DB::table(config('activitylog.table_name'))->insert([
                'log_name' => 'header-fixture',
                'description' => 'Synthetic recent activity',
                'causer_type' => User::class,
                'causer_id' => $this->employee->id,
                'created_at' => now()->subMinutes($minutesAgo),
                'updated_at' => now()->subMinutes($minutesAgo),
            ]);
        }
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $xpath = $this->xpath($component->html());
        $account = $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[@data-profile-account-status]');
        $this->assertCount(1, $account);
        $this->assertSame($active ? 'active' : 'inactive', $account->item(0)->getAttribute('data-profile-account-status'));
        $this->assertSame($active ? 'Aktiv' : 'Inaktiv', $this->text($account->item(0)));
        $presence = $this->headerNode($xpath, 'employee-profile__presence');
        $this->assertSame($online ? 'true' : 'false', $presence->getAttribute('data-online'));
        $expected = ! $active ? ucfirst(__('app.inactive')) : ($online ? __('app.online') : __('app.offline'));
        $this->assertSame($expected, $this->text($presence));
        $this->assertSame($active, $this->employee->fresh()->status);
    }

    public static function presenceCases(): array
    {
        return [
            'active but never online' => [true, null, false],
            'active with recent activity' => [true, 2, true],
            'active with old activity' => [true, 8, false],
            'inactive despite recent activity' => [false, 2, false],
        ];
    }

    public function test_personnel_fact_and_header_editing_follow_existing_master_data_and_employee_permissions(): void
    {
        Profile::create([
            'user_id' => $this->employee->id,
            'personnel_nr' => 'HEADER-SENSITIVE-FIXTURE-NR',
            'position' => 'HEADER-SENSITIVE-FIXTURE-POSITION',
        ]);
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['employees.view' => true],
        ]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        $component = Livewire::actingAs($manager->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('HEADER-SENSITIVE-FIXTURE-NR')
            ->assertDontSee('HEADER-SENSITIVE-FIXTURE-POSITION')->assertViewHas('canViewMasterData', false);
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[@data-native-inline-edit-trigger]'));
        $this->assertCount(0, $xpath->query('//*[@data-panel-tab="locations"]'));

        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSee('HEADER-SENSITIVE-FIXTURE-NR');
        $facts = $this->headerNode($this->xpath($component->html()), 'employee-profile__facts');
        $this->assertStringContainsString('HEADER-SENSITIVE-FIXTURE-NR', $facts->textContent);
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-profile-fact="personnel"]/dd'));
    }

    public function test_long_and_html_like_identity_values_are_escaped_and_not_replaced(): void
    {
        $name = 'Sehr langer synthetischer Mitarbeitername <script>fixtureOnly()</script> & Mehrfachname';
        $email = 'very-long-header-refinement-fixture-with-full-value@example.invalid';
        $position = 'Regionaler Wagenmeister <img src=x onerror=fixtureOnly()> & Ausbildung';
        $this->employee->forceFill(['name' => $name, 'email' => $email])->save();
        Profile::create(['user_id' => $this->employee->id, 'position' => $position]);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $xpath = $this->xpath($component->html());
        $displayName = $this->headerNode($xpath, 'employee-profile__name-text');
        $displayEmail = $this->headerNode($xpath, 'employee-profile__email-text');
        $this->assertSame($name, $displayName->textContent);
        $this->assertSame($name, $displayName->getAttribute('title'));
        $this->assertSame($email, $displayEmail->textContent);
        $this->assertSame($email, $displayEmail->getAttribute('title'));
        $this->assertContains($position, $this->headerBadges($component->html()));
        $this->assertCount(0, $xpath->query('//header[contains(@class,"employee-profile__identity")]//script'));
        $this->assertCount(0, $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[@onerror]'));
    }

    #[DataProvider('categorySelections')]
    public function test_category_and_native_child_tabs_keep_server_owned_manual_activation(string $firstTab, string $category): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        if ($firstTab !== 'userDetails') {
            $component->call('setProfileTab', $firstTab)->assertSuccessful();
        }
        $component->assertSet('profileTab', $firstTab);
        $definitions = [];
        $component->assertViewHas('employeeProfileTabs', function (array $tabs) use (&$definitions): bool {
            $definitions = $tabs;

            return true;
        });
        $expectedCounts = [];
        foreach ($definitions as $definition) {
            $group = $definition['group'] ?? 'Profil & Stammdaten';
            $expectedCounts[$group] = ($expectedCounts[$group] ?? 0) + 1;
        }
        $xpath = $this->xpath($component->html());
        $groups = $xpath->query('//*[@data-profile-navigation]//nav[contains(@class,"employee-profile__groups")]//button');
        $this->assertCount(4, $groups);
        $selectedGroups = $xpath->query('//*[@data-profile-navigation]//nav//button[@aria-current="page"]');
        $this->assertCount(1, $selectedGroups);
        $this->assertStringContainsString($category, $this->text($selectedGroups->item(0)));
        foreach ($groups as $group) {
            $this->assertSame('setProfileTab', $group->getAttribute('wire:target'));
            $this->assertStringContainsString('setProfileTab', $group->getAttribute('x-on:click'));
            $this->assertFalse($group->hasAttribute('x-on:focus'));
            $this->assertNotSame('tab', $group->getAttribute('role'));
            $this->assertSame('disabled', $group->getAttribute('wire:loading.attr'));
            $groupKey = $group->getAttribute('data-profile-group');
            $this->assertArrayHasKey($groupKey, $expectedCounts);
            $counts = $xpath->query('.//*[contains(@class,"employee-profile__group-count") and @aria-hidden="true"]', $group);
            $this->assertCount(1, $counts);
            $this->assertSame((string) $expectedCounts[$groupKey], $this->text($counts->item(0)));
        }
        $tablists = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]');
        $this->assertCount(1, $tablists);
        foreach (['arrow-right', 'arrow-left', 'home', 'end'] as $key) {
            $this->assertNotSame('', $tablists->item(0)->getAttribute('x-on:keydown.'.$key.'.prevent'));
        }
        $tabs = $xpath->query('//*[@data-profile-navigation]//*[@role="tab"]');
        $this->assertGreaterThan(0, $tabs->length);
        foreach ($tabs as $tab) {
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertStringContainsString('setProfileTab', $tab->getAttribute('x-on:click'));
            $this->assertFalse($tab->hasAttribute('x-on:focus'));
            $this->assertSame('employee-profile-'.$this->employee->id.'-tab-'.$tab->getAttribute('data-panel-tab'), $tab->getAttribute('id'));
            $panels = $xpath->query('//*[@id="'.$tab->getAttribute('aria-controls').'"]');
            $this->assertCount(1, $panels);
            $this->assertSame($tab->getAttribute('id'), $panels->item(0)->getAttribute('aria-labelledby'));
        }
        $selected = $xpath->query('//*[@data-profile-navigation]//*[@role="tab" and @aria-selected="true"]');
        $this->assertCount(1, $selected);
        $this->assertSame($firstTab, $selected->item(0)->getAttribute('data-panel-tab'));
        $this->assertSame('0', $selected->item(0)->getAttribute('tabindex'));
        Http::assertNothingSent();
    }

    public static function categorySelections(): array
    {
        return [
            'profile' => ['userDetails', 'Profil'],
            'personnel file' => ['qualifications', 'Personalakte'],
            'development' => ['workflows', 'Entwicklung'],
            'working time' => ['accounts', 'Arbeitszeit'],
        ];
    }

    public function test_identity_and_menu_refinement_does_not_read_location_history_or_modify_employee_data(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'SYNTHETIC-HEADER-READ-ONLY']);
        $beforeUser = $this->employee->fresh()->getRawOriginal();
        $beforeProfile = Profile::where('user_id', $this->employee->id)->first()->getRawOriginal();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
                ->assertSuccessful();
            $component->call('setProfileTab', 'masterData')->assertSuccessful()
                ->call('setProfileTab', 'userDetails')->assertSuccessful();
            $locationReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:from|join)\s+["`]?\b(?:employee_location_(?:points|consents|consent_events)|mobile_location_(?:installations|codes))\b/i',
                $query['query'],
            ));
            $this->assertSame([], array_values($locationReads));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($beforeUser, $this->employee->fresh()->getRawOriginal());
        $this->assertSame($beforeProfile, Profile::where('user_id', $this->employee->id)->first()->getRawOriginal());
        $this->assertDatabaseCount('employee_location_points', 0);
        $this->assertDatabaseCount('employee_location_consents', 0);
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

    private function headerNode(DOMXPath $xpath, string $class): DOMElement
    {
        $nodes = $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        $this->assertCount(1, $nodes, $class);

        return $nodes->item(0);
    }

    private function headerBadges(string $html): array
    {
        $xpath = $this->xpath($html);
        $badges = $xpath->query('//header[contains(@class,"employee-profile__identity")]//*[contains(@class,"employee-profile__identity-labels")]//*[contains(concat(" ",normalize-space(@class)," ")," rt-ui-badge ")]');
        $values = [];
        foreach ($badges as $badge) {
            $values[] = $this->text($badge);
        }

        return $values;
    }

    private function text(DOMElement $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
