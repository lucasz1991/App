<?php

namespace Tests\Feature;

use App\Livewire\Operations\LexwareSettings;
use App\Livewire\Operations\PersonnelPageWorkspace;
use App\Models\EmployeePayrollReference;
use App\Models\LexwareProfile;
use App\Models\User;
use App\Services\Operations\LexwareProfileService;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class LexwareSettingsUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_11_27_120000_create_lexware_profiles.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    public function test_tool_is_available_with_no_references_and_only_provides_a_fixed_sample(): void
    {
        $this->assertArrayHasKey('lexware-settings', PersonnelPageWorkspace::availableSections($this->admin, 'payroll'));
        Livewire::actingAs($this->admin)->test(PersonnelPageWorkspace::class, [
            'page' => 'payroll', 'initialSection' => 'lexware-settings',
        ])->assertSeeLivewire(LexwareSettings::class)->assertSee('Musterdatei herunterladen');

        $panel = Livewire::test(LexwareSettings::class)
            ->assertSet('employer', '')
            ->assertSee('noch keine Arbeitgeberreferenz')
            ->assertDontSee('Einstellungen speichern');
        $panel->call('downloadSample')
            ->assertFileDownloaded('Lexware-MUSTER-2026-11.txt', "Jahr;Monat;Personalnummer;Lohnartnummer;Wert\r\n2026;11;00017;0001;8,00\r\n");
        $panel->call('downloadSample', ['user_id' => $this->admin->id])
            ->assertHasErrors(['sample'])->assertNoFileDownloaded();
        $this->assertSame(0, LexwareProfile::count());
    }

    public function test_profile_must_be_saved_and_tested_before_the_selected_company_can_be_enabled(): void
    {
        $this->addReference('NORD');
        $panel = Livewire::actingAs($this->admin)->test(LexwareSettings::class)
            ->assertSet('employer', 'NORD')->assertSee('Produktivexport deaktiviert')
            ->set('form.company_name', 'Testfirma Nord')
            ->call('save')->assertHasNoErrors()->assertSee('Mindestens eine Lohnart zuordnen.')
            ->set('form.import_profile', 'Windows-Stundenimport')
            ->call('addMapping')
            ->set('form.mappings.0.source_code', 'STD')
            ->set('form.mappings.0.target_code', '1')
            ->call('save')->assertHasNoErrors()->assertSet('form.mappings.0.target_code', '0001')
            ->call('activate')->assertHasErrors(['workflow'])
            ->call('confirmTest')->assertHasErrors(['testAcknowledged'])
            ->set('testAcknowledged', true)->call('confirmTest')->assertHasNoErrors()
            ->set('form.company_name', 'Ungespeicherte Änderung')
            ->call('activate')->assertHasErrors(['workflow']);
        $this->assertFalse(LexwareProfile::sole()->active);
        $panel->call('selectEmployer', 'NORD')
            ->call('activate')->assertHasNoErrors()->assertSee('Produktivexport aktiv')
            ->call('deactivate')->assertHasNoErrors()->assertSee('Produktivexport deaktiviert')->assertSee('Export gesperrt');
        $this->assertFalse(LexwareProfile::sole()->active);
        $this->assertNull(LexwareProfile::sole()->tested_at);
    }

    public function test_direct_mount_and_sample_access_require_current_management_permission(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->assertArrayNotHasKey('lexware-settings', PersonnelPageWorkspace::availableSections($staff, 'payroll'));
        Livewire::actingAs($staff)->test(LexwareSettings::class)->assertForbidden();
        $panel = Livewire::actingAs($this->admin)->test(LexwareSettings::class);
        $this->admin->forceFill(['status' => false])->save();
        $panel->call('downloadSample')->assertForbidden();
    }

    public function test_saved_draft_renders_hints_associated_with_the_incomplete_fields(): void
    {
        $this->addReference('NORD');
        $panel = Livewire::actingAs($this->admin)->test(LexwareSettings::class)
            ->call('addMapping')->set('form.mappings.0.quantity_kind', '')
            ->call('save')->assertHasNoErrors();

        foreach (['company_name', 'import_profile', '0-source_code', '0-target_code', '0-quantity_kind'] as $key) {
            $panel->assertSeeHtml('aria-describedby="lexware-hint-'.$key.'"')
                ->assertSeeHtml('id="lexware-hint-'.$key.'"');
        }
        $panel->assertDontSee('Probeimport bestätigen')
            ->set('form.mappings.0.target_code', 'A123')->call('save')
            ->assertHasErrors('form.mappings.0.target_code')
            ->assertSeeHtml('aria-describedby="formmappings0target-code-error"');
    }

    public function test_reference_issues_are_visible_before_confirmation_and_while_the_profile_is_active(): void
    {
        $this->addReference('NORD');
        $service = app(LexwareProfileService::class);
        $profile = $service->save('NORD', 0, [
            'company_name' => 'Testfirma', 'import_profile' => 'Testimport',
            'mappings' => [['source_code' => 'STD', 'target_code' => '1', 'quantity_kind' => 'base']],
        ], $this->admin);
        $tested = $service->confirmTest('NORD', $profile->revision, $this->admin);
        $active = $service->activate('NORD', $tested->revision, $this->admin);
        $this->addReference('NORD', '25');

        $panel = Livewire::actingAs($this->admin)->test(LexwareSettings::class)
            ->assertSee('Mehrdeutige Personalnummern')->assertSee('Lohnzuordnung öffnen')->assertSee('Produktivexport sperren');
        $panel->call('deactivate')->assertHasNoErrors()
            ->assertSee('Produktivexport deaktiviert')->assertSee('Mehrdeutige Personalnummern')
            ->assertDontSee('Probeimport bestätigen');
        $this->assertFalse($active->fresh()->active);

        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.lexware.manage', fn (User $actor) => $actor->is($manager));
        Gate::define('operations.time.export', fn (User $actor) => false);
        Livewire::actingAs($manager)->test(LexwareSettings::class)
            ->assertSee('Mehrdeutige Personalnummern')->assertDontSee('Lohnzuordnung öffnen');
    }

    private function addReference(string $company, string $number = '00025'): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        EmployeePayrollReference::create([
            'user_id' => $employee->id,
            'updated_by' => $this->admin->id,
            'employer_reference' => $company,
            'personnel_number' => $number,
        ]);
    }
}
