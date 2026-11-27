<?php

namespace Tests\Feature;

use App\Livewire\Operations\TimeAdministration;
use App\Models\OperationsMonthClosing;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\LexwareExportService;
use App\Services\Operations\LexwareProfileService;
use App\Services\Operations\OperationsRuleEvaluationService;
use App\Services\Operations\PayrollClosingService;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\PersonnelScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class LexwarePayrollWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;
    private User $reviewer;
    private User $employee;
    private LexwareProfileService $profiles;
    private LexwareExportService $exports;
    private const HEADER = "Jahr;Monat;Personalnummer;Lohnartnummer;Wert\r\n";
    private const EMPLOYER = 'TEST-NORD';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_102000_create_operations_enhancements.php',
            '2026_11_27_120000_create_lexware_profiles.php',
            '2026_11_27_140100_create_lexware_export_runs.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->travelTo(CarbonImmutable::parse('2026-06-18T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = $this->staff();
        $this->reference($this->employee, self::EMPLOYER, '00074');
        $this->profiles = app(LexwareProfileService::class);
        $this->exports = app(LexwareExportService::class);
        foreach (['internal' => 'STD', 'work' => 'DIENST'] as $activity => $code) {
            app(OperationsRuleEvaluationService::class)->configure([
                'name' => 'Regel '.$code, 'kind' => 'remuneration', 'user_id' => $this->employee->id,
                'starts_on' => '2026-01-01', 'confirmed' => true,
                'configuration' => [
                    'activity' => $activity, 'wage_code' => $code, 'multiplier_bps' => 10000,
                    'rounding_minutes' => 0, 'rounding_mode' => 'none', 'additive' => false,
                ],
            ], $this->admin);
        }
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    private function reference(User $employee, string $employer, string $number, ?int $expectedRevision = null): void
    {
        app(PayrollReferenceService::class)->save($employee->id, $expectedRevision, [
            'employer_reference' => $employer, 'personnel_number' => $number,
        ], $this->admin);
    }

    private function settings(string $target = '0001'): array
    {
        return [
            'company_name' => 'Synthetische Testfirma', 'import_profile' => 'Windows-Stundenimport',
            'product_version' => '2026',
            'mappings' => [
                ['source_code' => 'STD', 'target_code' => $target, 'quantity_kind' => 'base', 'disjoint' => true],
                ['source_code' => 'DIENST', 'target_code' => $target, 'quantity_kind' => 'base', 'disjoint' => true],
            ],
        ];
    }

    private function enable(?array $settings = null): int
    {
        $draft = $this->profiles->save(self::EMPLOYER, 0, $settings ?? $this->settings(), $this->admin);
        $tested = $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin);
        $active = $this->profiles->activate(self::EMPLOYER, $tested->revision, $this->admin);

        return $active->config_version;
    }

    private function closeDay(int $standardSeconds = 16200, int $dutySeconds = 12600): OperationsMonthClosing
    {
        $start = CarbonImmutable::parse('2026-02-02T07:00:00Z');
        $handoff = $start->addSeconds($standardSeconds);
        $end = $handoff->addSeconds($dutySeconds);
        $entry = WorkTimeEntry::create([
            'user_id' => $this->employee->id, 'work_context' => 'internal', 'status' => 'approved',
            'starts_at' => $start, 'ends_at' => $end, 'timezone' => 'Europe/Berlin', 'pause_seconds' => 0,
            'plan_snapshot' => ['day_clock' => true, 'valuation' => ['internal' => 10000, 'work' => 10000]],
        ]);
        foreach ([['internal', $start, $handoff], ['work', $handoff, $end]] as [$activity, $from, $until]) {
            $entry->activities()->create(['kind' => $activity, 'starts_at' => $from, 'ends_at' => $until, 'source' => 'reported']);
        }
        $closings = app(PayrollClosingService::class);
        $prepared = $closings->prepare($this->employee, '2026-02', $this->admin);

        return $closings->close($prepared->id, $prepared->revision, $this->reviewer);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('A blocked operation succeeded.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
    }

    private function forbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('An out-of-scope operation succeeded.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        } catch (AuthorizationException $error) {
            $this->assertSame(403, $error->status() ?? 403);
        }
    }

    public function test_draft_and_confirmed_trial_leave_live_data_blocked_until_explicit_activation(): void
    {
        $closing = $this->closeDay();
        $draft = $this->profiles->save(self::EMPLOYER, 0, $this->settings(), $this->admin);
        $this->assertFalse($draft->active);
        $this->assertSame(self::HEADER."2026;11;00017;0001;8,00\r\n", LexwareExportService::sample());
        $this->invalid(fn () => $this->profiles->activate(self::EMPLOYER, $draft->revision, $this->admin));
        $tested = $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin);
        $this->assertFalse($tested->active);
        $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $tested->config_version, true));
        $this->invalid(fn () => $this->profiles->activate(self::EMPLOYER, $draft->revision, $this->admin));
        $active = $this->profiles->activate(self::EMPLOYER, $tested->revision, $this->admin);
        $this->assertTrue($active->active);
        $this->assertSame($tested->config_version, $active->config_version);
        $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $active->config_version));
        $result = $this->exports->download($this->admin, $closing->id, $closing->revision, $active->config_version, true);
        $this->assertSame(self::HEADER."2026;02;00074;0001;8,00\r\n", $result['content']);
        $run = $this->exports->inspect($this->admin, $closing->id)['run'];
        Livewire::actingAs($this->admin)->test(TimeAdministration::class, [
            'tab' => 'payroll', 'payrollExportOnly' => true, 'initialRecordId' => $closing->id,
        ])->assertSee('Lexware lohn+gehalt für Windows')->assertSee('8,00')
            ->call('downloadLexware', $active->config_version)->assertFileDownloaded($result['filename'])
            ->set('lexwareResults.'.$run['id'], 'full')->call('recordLexwareResult', $run['id'])
            ->assertHasNoErrors()->assertSee('Vollständig importiert');
    }

    public function test_incomplete_or_ambiguous_mappings_can_be_drafted_but_cannot_be_confirmed(): void
    {
        $draft = $this->profiles->save(self::EMPLOYER, 0, [], $this->admin);
        $this->assertFalse($draft->active);
        $this->invalid(fn () => $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin));
        foreach ([
            'mappings.1.disjoint' => false,
            'mappings.1.quantity_kind' => 'supplement',
            'mappings.1.source_code' => 'STD',
        ] as $field => $value) {
            $settings = $this->settings();
            data_set($settings, $field, $value);
            $draft = $this->profiles->save(self::EMPLOYER, $draft->revision, $settings, $this->admin);
            $this->invalid(fn () => $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin));
            $this->assertFalse($this->profiles->get(self::EMPLOYER, $this->admin)->active);
        }
    }

    public function test_rounds_after_aggregation_and_freezes_one_run_for_repeated_downloads(): void
    {
        $version = $this->enable();
        $closing = $this->closeDay(17, 17);
        $first = $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $run = $this->exports->inspect($this->admin, $closing->id)['run'];
        $this->assertSame(self::HEADER."2026;02;00074;0001;0,01\r\n", $first['content']);
        $this->assertSame(hash('sha256', $first['content']), $run['sha256']);
        $this->assertSame('open', $run['status']);
        $this->assertSame($first, $this->exports->download($this->admin, $closing->id, $closing->revision, $version));
        $this->assertSame(['DIENST' => 17, 'STD' => 17], $run['rows'][0]['source_seconds']);
        $this->assertSame($run, $this->exports->inspect($this->admin, $closing->id)['run']);
        $this->invalid(fn () => $this->exports->recordResult($this->admin, $closing->id, 'full', $run['id'] + 1));
        $this->invalid(fn () => $this->exports->recordResult($this->admin, $closing->id, 'partial', $run['id'], ['0001']));
        $this->exports->recordResult($this->admin, $closing->id, 'rejected', $run['id']);
        $this->assertSame('rejected', $this->exports->inspect($this->admin, $closing->id)['run']['status']);
        $this->invalid(fn () => $this->exports->recordResult($this->admin, $closing->id, 'full', $run['id']));
    }

    public function test_partial_import_requires_a_known_nonempty_proper_subset_of_delivered_rows(): void
    {
        $settings = $this->settings();
        $settings['mappings'][1]['target_code'] = '0002';
        $version = $this->enable($settings);
        $closing = $this->closeDay();
        $file = $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $this->assertSame(self::HEADER."2026;02;00074;0001;4,50\r\n2026;02;00074;0002;3,50\r\n", $file['content']);
        $run = $this->exports->inspect($this->admin, $closing->id)['run'];
        foreach ([[], ['9999'], ['0001', '0002']] as $invalidSelection) {
            $this->invalid(fn () => $this->exports->recordResult($this->admin, $closing->id, 'partial', $run['id'], $invalidSelection));
        }
        Livewire::actingAs($this->admin)->test(TimeAdministration::class, [
            'tab' => 'payroll', 'payrollExportOnly' => true, 'initialRecordId' => $closing->id,
        ])->set('lexwareResults.'.$run['id'], 'partial')
            ->set('lexwareImportedRows.'.$run['id'].'.0002', true)
            ->call('recordLexwareResult', $run['id'])->assertHasNoErrors();
        $recorded = $this->exports->inspect($this->admin, $closing->id)['run'];
        $this->assertSame('partial', $recorded['status']);
        $this->assertSame(['0002'], $recorded['imported_codes']);
        $this->invalid(fn () => $this->exports->recordResult($this->admin, $closing->id, 'partial', $run['id'], ['0001']));
    }

    public function test_disable_blocks_an_existing_file_preserves_history_and_keeps_generic_csv_working(): void
    {
        $version = $this->enable();
        $closing = $this->closeDay();
        $sources = ['DIENST' => 12600, 'STD' => 16200];
        $preview = $this->exports->inspect($this->admin, $closing->id);
        $this->assertSame($sources, $preview['rows'][0]['source_seconds']);
        $this->assertSame(28800, $preview['rows'][0]['raw_seconds']);
        $details = Livewire::actingAs($this->admin)->test(TimeAdministration::class, [
            'tab' => 'payroll', 'payrollExportOnly' => true, 'initialRecordId' => $closing->id,
        ])->assertSee('DIENST 3:30 · STD 4:30');
        $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $run = $this->exports->inspect($this->admin, $closing->id)['run'];
        $active = $this->profiles->get(self::EMPLOYER, $this->admin);
        $this->profiles->deactivate(self::EMPLOYER, $active->revision, $this->admin);
        $history = $this->profiles->history(self::EMPLOYER, $this->admin);
        $this->assertSame(['deactivated', 'activated', 'test_confirmed', 'saved'], array_column($history, 'event'));
        $this->assertSame([$this->admin->id], array_values(array_unique(array_column($history, 'actor_id'))));
        $this->assertSame([$version], array_values(array_unique(array_column($history, 'config_version'))));
        $state = $this->exports->inspect($this->admin, $closing->id);
        $this->assertFalse($state['available']);
        $this->assertSame($run['id'], $state['run']['id']);
        $this->assertSame($sources, $state['run']['rows'][0]['source_seconds']);
        $details->call('$refresh')->assertSee('DIENST 3:30 · STD 4:30')->assertDontSee('Identische Datei erneut herunterladen');
        $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true));
        $generic = app(PayrollClosingService::class)->csv($closing->id, $closing->revision, $this->admin);
        $this->assertStringContainsString('STD;16200', $generic);
        $this->assertStringContainsString('DIENST;12600', $generic);
    }

    public function test_supplement_uses_its_own_wage_row_without_increasing_the_base_hours(): void
    {
        app(OperationsRuleEvaluationService::class)->configure([
            'name' => 'Synthetische Dienstzulage', 'kind' => 'remuneration', 'user_id' => $this->employee->id,
            'starts_on' => '2026-01-01', 'confirmed' => true,
            'configuration' => [
                'activity' => 'work', 'wage_code' => 'DIENSTZULAGE', 'multiplier_bps' => 10000,
                'rounding_minutes' => 0, 'rounding_mode' => 'none', 'additive' => true,
            ],
        ], $this->admin);
        $settings = $this->settings();
        $settings['mappings'][] = [
            'source_code' => 'DIENSTZULAGE', 'target_code' => '0097', 'quantity_kind' => 'supplement', 'disjoint' => false,
        ];
        $version = $this->enable($settings);
        $closing = $this->closeDay();
        $file = $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $this->assertSame(self::HEADER."2026;02;00074;0001;8,00\r\n2026;02;00074;0097;3,50\r\n", $file['content']);
    }

    public function test_mapping_changes_revoke_approval_and_cannot_replace_an_existing_run(): void
    {
        $version = $this->enable();
        $closing = $this->closeDay();
        $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $active = $this->profiles->get(self::EMPLOYER, $this->admin);
        $changed = $this->profiles->save(self::EMPLOYER, $active->revision, $this->settings('0002'), $this->admin);
        $this->assertFalse($changed->active);
        $this->assertNull($changed->tested_at);
        $this->assertGreaterThan($version, $changed->config_version);
        $this->invalid(fn () => $this->profiles->activate(self::EMPLOYER, $changed->revision, $this->admin));
        $this->invalid(fn () => $this->profiles->save(self::EMPLOYER, $active->revision, $this->settings(), $this->admin));
        $checked = $this->profiles->confirmTest(self::EMPLOYER, $changed->revision, $this->admin);
        $changed = $this->profiles->activate(self::EMPLOYER, $checked->revision, $this->admin);
        $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $changed->config_version, true));
    }

    public function test_current_reference_must_still_belong_unambiguously_to_the_employee(): void
    {
        $draft = $this->profiles->save(self::EMPLOYER, 0, $this->settings(), $this->admin);
        $peer = $this->staff();
        $this->reference($peer, self::EMPLOYER, '74');
        $this->invalid(fn () => $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin));
        $this->reference($peer, 'TEST-SUED', '74', 1);
        $tested = $this->profiles->confirmTest(self::EMPLOYER, $draft->revision, $this->admin);
        $active = $this->profiles->activate(self::EMPLOYER, $tested->revision, $this->admin);
        $closing = $this->closeDay();
        $download = fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $active->config_version, true);

        // An alias introduced after approval prevents even the first handoff.
        $this->reference($peer, self::EMPLOYER, '74', 2);
        $this->invalid($download);
        $this->assertNull($this->exports->inspect($this->admin, $closing->id)['run']);
        $this->reference($peer, 'TEST-SUED', '74', 3);
        $download();
        $run = $this->exports->inspect($this->admin, $closing->id)['run'];

        // Reassignment after export leaves its original history and outcome available.
        $this->reference($this->employee, self::EMPLOYER, '00080', 1);
        $this->invalid($download);
        $this->reference($peer, self::EMPLOYER, '00074', 4);
        $this->invalid($download);
        $this->exports->recordResult($this->admin, $closing->id, 'full', $run['id']);
        $state = $this->exports->inspect($this->admin, $closing->id);
        $this->assertFalse($state['available']);
        $this->assertSame('full', $state['run']['status']);
    }

    public function test_new_month_revision_requires_reconciliation_of_the_existing_run(): void
    {
        $version = $this->enable();
        $closing = $this->closeDay();
        $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true);
        $closings = app(PayrollClosingService::class);
        $closings->reopen($closing->id, $closing->revision, 'Synthetische Korrektur', $this->admin);
        $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true));
        $prepared = $closings->prepare($this->employee, $closing->month, $this->admin);
        $new = $closings->close($prepared->id, $prepared->revision, $this->reviewer);
        $this->invalid(fn () => $this->exports->download($this->admin, $new->id, $new->revision, $version, true));
    }

    public function test_old_unverifiable_or_unmapped_snapshots_do_not_create_a_payroll_file(): void
    {
        $version = $this->enable();
        $closing = $this->closeDay();
        $sealed = $closing->snapshot;
        $revision = $closing->revisions()->where('action', 'closed')->sole();

        foreach ([
            'schema' => 'railtime-payroll-preparation-v1',
            'payroll_reference.personnel_number' => '=invalid',
            'entries.0.quantities.0.wage_code' => 'UNKNOWN',
            'entries.0.quantities.0.multiplier_bps' => 15000,
            'entries.0.quantities.0.quantity_seconds' => 7200,
            'entries.0.quantities.0.additive' => null,
            'entries.0.status' => 'returned',
            'complete' => false,
        ] as $key => $value) {
            // Simulate a sealed record from an older or incompatible installation.
            $snapshot = $sealed;
            data_set($snapshot, $key, $value);
            $closing->update(['snapshot' => $snapshot]);
            $revision->update(['snapshot' => $snapshot]);
            $this->invalid(fn () => $this->exports->download($this->admin, $closing->id, $closing->revision, $version, true));
            $this->assertNull($this->exports->inspect($this->admin, $closing->id)['run'], $key);
        }
    }

    public function test_scoped_manager_cannot_manage_other_employers_or_a_partial_workforce(): void
    {
        $manager = $this->staff();
        $outside = $this->staff();
        $this->reference($outside, 'TEST-SUED', '00074');
        $grants = ['operations.lexware.manage', 'operations.time.export'];
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($grants, true)]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->employee, [
            'responsible_user_id' => $manager->id, 'starts_on' => '2026-01-01', 'abilities' => $grants,
        ], $this->admin);
        $nord = $this->profiles->save(self::EMPLOYER, 0, $this->settings(), $manager);
        $this->assertFalse($nord->active);
        $this->forbidden(fn () => $this->profiles->save('TEST-SUED', 0, $this->settings(), $manager));
        $this->reference($this->staff(), self::EMPLOYER, '00075');
        $this->forbidden(fn () => $this->profiles->confirmTest(self::EMPLOYER, $nord->revision, $manager));
        $this->forbidden(fn () => $this->profiles->history(self::EMPLOYER, $manager));
        $closing = $this->closeDay();
        $this->forbidden(fn () => $this->exports->download($outside, $closing->id, $closing->revision, $nord->config_version, true));
    }
}
