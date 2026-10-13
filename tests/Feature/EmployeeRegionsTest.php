<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Livewire\Profile\EmployeeRegions;
use App\Models\OperationAudit;
use App\Models\Shift;
use App\Models\StaffRegionalPreference;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Support\Operations\GermanFederalStates;
use App\Support\Operations\GermanyMap;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeRegionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = dirname(__DIR__, 2).'/storage/framework/views/employee-regions-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
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
            '2026_10_06_220000_create_staff_regional_preferences_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->compiledViews);
        parent::tearDown();
        putenv($this->previousCompiledEnvironment[0] === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$this->previousCompiledEnvironment[0]);
        foreach (['_ENV', '_SERVER'] as $index => $environment) {
            if ($this->previousCompiledEnvironment[$index + 1] === null) {
                unset($GLOBALS[$environment]['VIEW_COMPILED_PATH']);
            } else {
                $GLOBALS[$environment]['VIEW_COMPILED_PATH'] = $this->previousCompiledEnvironment[$index + 1];
            }
        }
    }

    private function editor(?User $actor = null)
    {
        return Livewire::actingAs($actor ?? $this->admin)->test(EmployeeRegions::class, ['userId' => $this->employee->id]);
    }

    private function manager(array $abilities): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        return $manager->fresh();
    }

    private function preferences(array $extra = []): array
    {
        return array_replace([
            'enabled' => true,
            'base_location' => 'Hamburg',
            'preferred_radius_km' => 50,
            'border_radius_km' => 150,
            'emergency_radius_km' => 400,
            'federal_states' => ['preferred' => ['DE-HH'], 'possible' => ['DE-NI'], 'emergency' => ['DE-BE']],
            'no_go_areas' => [],
        ], $extra);
    }

    public function test_unconfigured_profile_is_neutral_without_inferring_a_home_address(): void
    {
        $this->employee->profile()->create(['city' => 'Hamburg', 'street' => 'Private example street']);
        $this->editor()->assertSuccessful()
            ->assertSet('preferences.enabled', false)
            ->assertSet('preferences.base_location', '')
            ->assertSet('preferences.federal_states', ['preferred' => [], 'possible' => [], 'emergency' => []])
            ->assertSet('revision', 0)->assertSet('dirty', false);
        $this->assertDatabaseCount('staff_regional_preferences', 0);
        $this->assertDatabaseCount('operation_audits', 0);
        Http::assertNothingSent();
    }

    public function test_zone_switch_moves_a_state_between_tiers_and_same_tier_toggle_removes_it(): void
    {
        $component = $this->editor()->call('toggleState', 'DE-HH')
            ->assertSet('preferences.federal_states.preferred', ['DE-HH'])->assertSet('dirty', true);
        foreach (['possible', 'emergency'] as $zone) {
            $component->call('selectZone', $zone)->call('toggleState', 'DE-HH')
                ->assertSet('preferences.federal_states.'.$zone, ['DE-HH']);
            foreach (array_diff(['preferred', 'possible', 'emergency'], [$zone]) as $other) {
                $component->assertSet('preferences.federal_states.'.$other, []);
            }
        }
        $component->call('toggleState', 'DE-HH')->assertSet('preferences.federal_states.emergency', [])
            ->call('reload')->assertSet('dirty', false);
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    public function test_save_persists_v2_for_only_the_locked_employee_with_audit_and_reloads_the_revision(): void
    {
        $outside = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->editor()->set('preferences', $this->preferences())
            ->call('save')->assertHasNoErrors()->assertSet('revision', 1)
            ->assertSet('preferences.schema_version', 2)->assertSet('dirty', false)
            ->assertSet('notice', 'Einsatzgebiete gespeichert.');
        $stored = StaffRegionalPreference::sole();
        $this->assertSame($this->employee->id, $stored->user_id);
        $this->assertSame(2, $stored->preferences['schema_version']);
        $this->assertSame($this->preferences()['federal_states'], $stored->preferences['federal_states']);
        $this->assertDatabaseMissing('staff_regional_preferences', ['user_id' => $outside->id]);
        $audit = OperationAudit::where('action', 'staff.regional_preferences.saved')->sole();
        $this->assertSame($this->employee->id, $audit->data['user_id']);
        $this->assertSame($this->admin->id, $audit->actor_id);
        Http::assertNothingSent();
    }

    public function test_legacy_exclusions_survive_editing_and_block_even_a_selected_emergency_state(): void
    {
        $service = app(StaffRegionalPreferenceService::class);
        $service->save($this->employee, $this->admin, [
            'enabled' => true, 'base_location' => 'Hamburg', 'preferred_radius_km' => 50, 'border_radius_km' => 100,
            'no_go_areas' => [['location' => 'Berlin', 'radius_km' => 20]],
        ], 0);
        $this->editor()->assertSet('preferences.schema_version', 1)
            ->call('selectZone', 'emergency')->call('toggleState', 'DE-BE')
            ->call('save')->assertHasNoErrors()->assertSet('revision', 2)
            ->assertSet('preferences.no_go_areas', [['location' => 'Berlin', 'radius_km' => 20]]);
        $assessment = $service->assessMany(new Shift(['location_name' => 'Berlin']), collect([$this->employee]))[$this->employee->id];
        $this->assertSame('no_go', $assessment['state']);
        $this->assertTrue($assessment['blocked']);
        $this->assertSame(2, StaffRegionalPreference::sole()->preferences['schema_version']);
    }

    public function test_concurrent_save_retains_the_winner_and_keeps_the_losing_draft_until_reload(): void
    {
        $first = $this->editor();
        $second = $this->editor()->set('preferences', $this->preferences(['base_location' => 'Berlin']));
        $first->set('preferences', $this->preferences())->call('save')->assertHasNoErrors();
        $winner = StaffRegionalPreference::sole()->getAttributes();
        $second->call('save')->assertHasErrors('preferences')->assertSet('revision', 0)
            ->assertSet('preferences.base_location', 'Berlin')->assertSet('dirty', true);
        $this->assertSame($winner, StaffRegionalPreference::sole()->getAttributes());
        $this->assertDatabaseCount('operation_audits', 1);
        $second->call('reload')->assertHasNoErrors()->assertSet('revision', 1)
            ->assertSet('preferences.base_location', 'Hamburg')->assertSet('dirty', false);
    }

    #[DataProvider('requiredPermissions')]
    public function test_management_needs_both_employee_and_operations_permissions(array $abilities, bool $allowed): void
    {
        $component = $this->editor($this->manager($abilities));
        if ($allowed) {
            $component->assertSuccessful()->set('preferences', $this->preferences())->call('save')->assertHasNoErrors();
            $this->assertDatabaseCount('staff_regional_preferences', 1);
        } else {
            $component->assertForbidden();
            $this->assertDatabaseCount('staff_regional_preferences', 0);
        }
    }

    public static function requiredPermissions(): array
    {
        return [
            'both' => [['employees.view', 'operations.manage'], true],
            'employee only' => [['employees.view'], false],
            'operations only' => [['operations.manage'], false],
        ];
    }

    public function test_staff_and_non_staff_targets_are_rejected_and_permission_revocation_stops_open_editor(): void
    {
        $this->editor($this->employee)->assertForbidden();
        Livewire::actingAs($this->admin)->test(EmployeeRegions::class, ['userId' => $this->admin->id])->assertForbidden();
        $manager = $this->manager(['employees.view', 'operations.manage']);
        $component = $this->editor($manager)->set('preferences', $this->preferences());
        $manager->currentTeam->forceFill(['rbac_permissions' => ['employees.view' => true]])->save();
        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    public function test_disabled_actor_cannot_toggle_or_save_an_already_open_editor(): void
    {
        $component = $this->editor();
        $this->admin->update(['status' => false]);
        $component->call('toggleState', 'DE-HH')->assertForbidden();
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    #[DataProvider('lockedFields')]
    public function test_client_cannot_retarget_the_employee_or_bypass_revision_checks(string $field, int $value): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->editor()->set($field, $value);
    }

    public static function lockedFields(): array
    {
        return ['employee' => ['userId', 999], 'revision' => ['revision', 999]];
    }

    #[DataProvider('invalidActions')]
    public function test_unknown_state_and_zone_actions_are_rejected(string $method, string $value): void
    {
        $this->editor()->call($method, $value)->assertStatus(422);
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    public static function invalidActions(): array
    {
        return ['state' => ['toggleState', 'DE-XX'], 'zone' => ['selectZone', 'anything']];
    }

    public function test_client_supplied_unknown_zone_cannot_be_used_for_a_state_mutation(): void
    {
        $this->editor()->set('activeZone', 'unknown')->call('toggleState', 'DE-HH')->assertStatus(422);
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    #[DataProvider('invalidPreferences')]
    public function test_invalid_saved_preferences_show_field_error_without_partial_changes(string $field, mixed $value, string $error): void
    {
        $component = $this->editor()->set('preferences', $this->preferences());
        $component->set('preferences.'.$field, $value)->call('save')->assertHasErrors('preferences.'.$error);
        $this->assertDatabaseCount('staff_regional_preferences', 0);
        $this->assertDatabaseCount('operation_audits', 0);
    }

    public static function invalidPreferences(): array
    {
        return [
            'unknown state' => ['federal_states.possible', ['DE-XX'], 'federal_states.possible.0'],
            'duplicate across tiers' => ['federal_states.emergency', ['DE-HH'], 'federal_states.emergency'],
            'reversed radii' => ['border_radius_km', 40, 'border_radius_km'],
            'emergency below possible' => ['emergency_radius_km', 100, 'emergency_radius_km'],
            'unrecognized origin' => ['base_location', 'Unbekanntes Depot', 'base_location'],
        ];
    }

    #[DataProvider('malformedDraftValues')]
    public function test_malformed_client_drafts_are_rejected_before_render_without_changing_saved_choices(string $field, mixed $value): void
    {
        app(StaffRegionalPreferenceService::class)->save($this->employee, $this->admin, $this->preferences(), 0);
        $before = StaffRegionalPreference::sole()->getAttributes();
        $this->editor()->set('preferences.'.$field, $value)->assertStatus(422);
        $this->assertSame($before, StaffRegionalPreference::sole()->getAttributes());
        $this->assertDatabaseCount('operation_audits', 1);
        Http::assertNothingSent();
    }

    public static function malformedDraftValues(): array
    {
        return [
            'states string' => ['federal_states', 'DE-HH'],
            'zone string' => ['federal_states.preferred', 'DE-HH'],
            'nested state array' => ['federal_states.preferred', [['DE-HH']]],
            'exclusions string' => ['no_go_areas', 'Berlin'],
            'exclusion item string' => ['no_go_areas', ['Berlin']],
            'origin array' => ['base_location', ['Hamburg']],
            'preferred radius array' => ['preferred_radius_km', [50]],
            'possible radius array' => ['border_radius_km', [150]],
            'emergency radius array' => ['emergency_radius_km', [400]],
        ];
    }

    public function test_editor_uses_dashboard_geometry_with_keyboard_access_and_local_radius_preview(): void
    {
        $component = $this->editor()->set('preferences', $this->preferences());
        $component->assertViewHas('statePaths', GermanFederalStates::paths())
            ->assertViewHas('basePoint', GermanyMap::projectCoordinates(53.55073, 9.99302));
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $states = $xpath->query('//*[local-name()="path" and @role="button"]');
        $this->assertCount(16, $states);
        foreach ($states as $state) {
            $this->assertSame('0', $state->getAttribute('tabindex'));
            $this->assertNotSame('', $state->getAttribute('aria-label'));
            $this->assertNotSame('', $state->getAttribute('x-on:keydown.enter.prevent'));
            $this->assertNotSame('', $state->getAttribute('x-on:keydown.space.prevent'));
        }
        $this->assertCount(3, $xpath->query('//*[contains(@class,"rt-regions__radii")]/*[local-name()="path"]'));
        $this->assertCount(16, $xpath->query('//*[contains(@class,"rt-regions__state-list")]/button'));
        $this->assertStringContainsString('In Planung berücksichtigen', $component->html());
        $this->assertStringContainsString('Luftlinie', $component->html());
        $this->assertDatabaseCount('staff_regional_preferences', 0);
        Http::assertNothingSent();
    }

    public function test_profile_navigation_places_employee_records_in_their_existing_authorized_sections(): void
    {
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertViewHas('employeeProfileTabs', function (array $tabs): bool {
                foreach ([
                    'regions' => 'Betrieb', 'planReviews' => 'Betrieb',
                    'masterData' => 'Personalakte', 'documents' => 'Personalakte', 'compensation' => 'Personalakte',
                    'training' => 'Entwicklung & Aufgaben', 'responsibilities' => 'Personalakte',
                    'ruleAssignments' => 'Arbeitszeit & Regeln',
                ] as $tab => $group) {
                    $this->assertSame($group, $tabs[$tab]['group']);
                }
                $this->assertSame('Regelprofile', $tabs['ruleAssignments']['label']);

                return true;
            })->call('setProfileTab', 'regions')->assertSuccessful()->assertSeeLivewire(EmployeeRegions::class)
            ->assertSet('userId', $this->employee->id);
        Livewire::actingAs($this->manager(['employees.view']))->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['regions']))
            ->call('setProfileTab', 'regions')->assertForbidden();
    }

    #[DataProvider('initialProfileTabs')]
    public function test_profile_deep_link_selects_only_an_authorized_known_tab(mixed $requestedTab, bool $fullAccess, string $expectedTab): void
    {
        $actor = $fullAccess ? $this->admin : $this->manager(['employees.view']);
        $component = Livewire::actingAs($actor)->withQueryParams(['profileTab' => $requestedTab])
            ->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', $expectedTab)
            ->assertSet('userId', $this->employee->id);
        if ($expectedTab === 'regions') {
            $component->assertSeeLivewire(EmployeeRegions::class);
        } else {
            $component->assertDontSeeLivewire(EmployeeRegions::class);
        }
        $this->assertDatabaseCount('staff_regional_preferences', 0);
    }

    public static function initialProfileTabs(): array
    {
        return [
            'allowed region link' => ['regions', true, 'regions'],
            'unauthorized region link' => ['regions', false, 'userDetails'],
            'unknown tab' => ['unrecognized', true, 'userDetails'],
            'array instead of tab' => [['regions'], true, 'userDetails'],
        ];
    }
}
