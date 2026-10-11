<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use App\Services\Operations\PersonnelScopeService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileMenuRefinementTest extends TestCase
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
            'name' => 'Menu Refinement Fixture',
            'email' => 'menu-refinement@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_profile_is_the_default_first_category_and_betrieb_remains_second_and_lazy(bool $embedded): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
                'userId' => $this->employee->id,
                'embedded' => $embedded,
            ])->assertSuccessful()->assertSet('profileTab', 'userDetails');
            $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:from|join)\s+["`]?\b(?:shifts|shift_assignments|employee_location_points)\b/i',
                $query['query'],
            ));
            $this->assertSame([], array_values($reads));
        } finally {
            DB::disableQueryLog();
        }

        $xpath = $this->xpath($component->html());
        $groups = $xpath->query('//*[@data-profile-navigation]//*[@data-profile-group]');
        $this->assertSame(['Profil & Stammdaten', 'Betrieb', 'Personalakte', 'Entwicklung & Aufgaben', 'Arbeitszeit & Regeln'], array_map(
            static fn ($group): string => $group->getAttribute('data-profile-group'),
            iterator_to_array($groups),
        ));
        $this->assertSame('page', $groups->item(0)->getAttribute('aria-current'));
        $this->assertSame('false', $groups->item(1)->getAttribute('aria-current'));
        $groupKeys = [];
        foreach ($groups as $group) {
            $key = $group->getAttribute('wire:key');
            $this->assertSame('employee-profile-'.$this->employee->id.'-group-'.Str::slug($group->getAttribute('data-profile-group')), $key);
            $groupKeys[] = $key;
        }
        $this->assertCount($groups->length, array_unique($groupKeys));
        $source = file_get_contents(resource_path('views/livewire/admin/user-profile.blade.php'));
        $this->assertStringNotContainsString('-group-{{ $loop->index }}', $source);
        $this->assertStringContainsString('operationsOverview', $groups->item(1)->getAttribute('x-on:click'));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        $this->assertCount(1, $xpath->query('//*[@data-profile-overview]'));

        $component->call('setProfileTab', 'operationsOverview')->assertSuccessful()->assertSet('profileTab', 'operationsOverview');
        $children = $this->children($component->html(), 'profile.employee-operations');
        $this->assertCount(1, $children);
        $this->assertSame($this->employee->id, $children[0]['data']['userId']);
        $this->assertSame('overview', $children[0]['data']['viewMode']);
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        $component->call('setProfileTab', 'userDetails')->assertSuccessful();
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        Http::assertNothingSent();
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    public function test_removed_overview_intro_does_not_remove_cards_or_their_native_editors(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertDontSee('Die wichtigsten Angaben auf einen Blick')
            ->assertDontSee('Angaben direkt bearbeiten')->assertDontSee('Profilübersicht');
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," employee-overview__intro ")]'));
        $cards = $xpath->query('//*[@data-profile-overview]/section');
        $this->assertCount(4, $cards);
        foreach ($cards as $card) {
            $this->assertTrue($card->hasAttribute('aria-labelledby'));
            $headings = $xpath->query('.//h3[@id="'.$card->getAttribute('aria-labelledby').'"]', $card);
            $this->assertCount(1, $headings);
            $this->assertNotSame('', trim($headings->item(0)->textContent));
            $this->assertCount(1, $xpath->query('./dl', $card));
        }
        foreach ([
            'employee-details-name' => 'name',
            'employee-contact-email' => 'email',
            'employee-contact-street' => 'street',
            'employee-overview-position' => 'position',
        ] as $id => $field) {
            $inputs = $xpath->query('//*[@data-profile-overview]//*[@id="'.$id.'"]');
            $this->assertCount(1, $inputs);
            $this->assertSame('inlineValues.'.$field, $inputs->item(0)->getAttribute('wire:model'));
            $this->assertCount(1, $xpath->query('//*[@data-profile-overview]//*[@for="'.$id.'" and @data-native-inline-edit-trigger]'));
        }
        $this->assertCount(1, $xpath->query('//*[@data-autosave-scope]'));
        $this->assertDatabaseCount('user_profiles', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('activeSections')]
    public function test_two_level_menu_keeps_manual_native_tabs_with_matching_ids_and_panels(string $tabKey, string $groupKey): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        if ($tabKey !== 'userDetails') {
            $component->call('setProfileTab', $tabKey)->assertSuccessful();
        }
        $component->assertSet('profileTab', $tabKey);
        $xpath = $this->xpath($component->html());
        $selectedGroups = $xpath->query('//*[@data-profile-navigation]//*[@data-profile-group and @aria-current="page"]');
        $this->assertCount(1, $selectedGroups);
        $this->assertSame($groupKey, $selectedGroups->item(0)->getAttribute('data-profile-group'));
        $this->assertNotSame('tab', $selectedGroups->item(0)->getAttribute('role'));
        $lists = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]');
        $this->assertCount(1, $lists);
        foreach (['arrow-right', 'arrow-left', 'home', 'end'] as $key) {
            $this->assertNotSame('', $lists->item(0)->getAttribute('x-on:keydown.'.$key.'.prevent'));
        }
        foreach ($xpath->query('//*[@data-profile-navigation]//*[@role="tab"]') as $tab) {
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertSame('disabled', $tab->getAttribute('wire:loading.attr'));
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
        $this->assertSame($tabKey, $selected->item(0)->getAttribute('data-panel-tab'));
        $this->assertSame('0', $selected->item(0)->getAttribute('tabindex'));
        Http::assertNothingSent();
    }

    public static function activeSections(): array
    {
        return [
            'personal overview' => ['userDetails', 'Profil & Stammdaten'],
            'operational overview' => ['operationsOverview', 'Betrieb'],
            'assigned shifts' => ['shifts', 'Betrieb'],
            'personal master data' => ['masterData', 'Profil & Stammdaten'],
        ];
    }

    public function test_invalid_pending_edit_preserves_profile_default_before_switching_to_betrieb(): void
    {
        $original = $this->employee->email;
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.email', 'not-an-email')
            ->call('setProfileTab', 'operationsOverview')->assertHasErrors('inlineValues.email')
            ->assertSet('profileTab', 'userDetails')->assertSet('dirtyInlineFields', ['email']);
        $this->assertSame($original, $this->employee->fresh()->email);
        $this->assertSame([], $this->children($component->html(), 'profile.employee-operations'));
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-panel-tab="userDetails" and @aria-selected="true"]'));
    }

    public function test_valid_pending_edit_saves_before_switching_to_operational_overview(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'phone' => '+49 000 100']);
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.phone', '+49 000 200')
            ->call('setProfileTab', 'operationsOverview')->assertHasNoErrors()
            ->assertSet('profileTab', 'operationsOverview')->assertSet('dirtyInlineFields', [])
            ->assertDispatched('employee-profile-field-saved');
        $this->assertSame('+49 000 200', Profile::firstWhere('user_id', $this->employee->id)->phone);
        $this->assertCount(1, $this->children($component->html(), 'profile.employee-operations'));
    }

    public function test_phone_setup_panel_is_before_account_segment_and_never_invents_permission_or_installation(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $panels = $xpath->query('//*[@data-profile-identity]//*[@data-profile-phone-status]');
        $this->assertCount(1, $panels);
        $this->assertCount(1, $xpath->query('following-sibling::*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__account ")]', $panels->item(0)));
        $this->assertCount(3, $xpath->query('.//*[@data-phone-status and @data-status="unknown"]', $panels->item(0)));
        foreach (['installation', 'push', 'location'] as $key) {
            $facts = $xpath->query('.//*[@data-phone-status="'.$key.'" and @data-status="unknown"]/dd', $panels->item(0));
            $this->assertCount(1, $facts);
            $this->assertSame('Nicht gemeldet', trim($facts->item(0)->textContent));
        }
        $this->assertCount(0, $xpath->query('.//*[@data-status="granted" or @data-status="installed" or @data-status="ready"]', $panels->item(0)));
        $text = trim(preg_replace('/\s+/u', ' ', $panels->item(0)->textContent));
        foreach (['Web-App', 'Push', 'Standort'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        $this->assertStringNotContainsString('Installiert', $text);
        $this->assertStringNotContainsString('Genehmigt', $text);
        $this->assertDatabaseCount('device_assignments', 0);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertDatabaseCount('employee_location_consents', 0);
        Http::assertNothingSent();
    }

    public function test_profile_reader_without_device_permission_cannot_see_phone_setup_evidence(): void
    {
        $reader = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['employees.view' => true],
        ]);
        $reader->teams()->attach($team->id);
        $reader->forceFill(['current_team_id' => $team->id])->save();
        $component = Livewire::actingAs($reader->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-profile-phone-status]'));
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-profile-identity]//*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__account ")]'));
        Http::assertNothingSent();
    }

    public function test_scoped_layout_rules_balance_multicolumn_cards_but_leave_mobile_height_natural(): void
    {
        $css = file_get_contents(resource_path('css/employee-profile-shell.css'));
        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.employee-profile\[data-profile-shell\] \.employee-overview\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);[^}]*grid-auto-rows:\s*1fr;[^}]*align-items:\s*stretch;/s',
            $css,
        );
        $mobileStart = strpos($css, '@media (max-width: 767.98px)');
        $this->assertNotFalse($mobileStart);
        $this->assertMatchesRegularExpression(
            '/\.employee-profile\[data-profile-shell\] \.employee-overview\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);[^}]*grid-auto-rows:\s*auto;[^}]*align-items:\s*start;/s',
            substr($css, $mobileStart),
        );
        $this->assertStringNotContainsString('.employee-overview__intro', $css);
        $this->assertStringContainsString(".employee-profile__groups .employee-profile__group[aria-current='page']:hover { color: var(--profile-area-selection);", $css);
        $this->assertStringContainsString(".employee-profile__tabs .rt-ops-panel__tab[aria-selected='true']:hover { color: theme('colors.white'); background: var(--profile-area-selection);", $css);
        $this->assertStringContainsString('.employee-profile__group::after { transition: none; }', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function test_revoked_device_permission_removes_phone_panel_on_native_profile_refresh(): void
    {
        $reader = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['employees.view' => true, 'devices.view' => true, 'employees.master-data.view' => true],
        ]);
        $reader->teams()->attach($team->id);
        $reader->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->employee, [
            'responsible_user_id' => $reader->id,
            'starts_on' => '2026-01-01',
            'abilities' => ['employees.master-data.view'],
        ], $this->admin);
        $component = Livewire::actingAs($reader->fresh())->test(UserProfile::class, ['userId' => $this->employee->id])->assertSuccessful();
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-profile-phone-status]'));

        $team->forceFill(['rbac_permissions' => ['employees.view' => true, 'employees.master-data.view' => true]])->save();
        $component->call('$refresh')->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-profile-phone-status]'));
        $this->assertSame([], $this->children($component->html(), 'profile.employee-location-history'));
        $this->assertDatabaseCount('employee_location_points', 0);
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
}
