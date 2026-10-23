<?php

namespace Tests\Feature;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Dropbox\PlanningEmployeeEvidence;
use App\Services\Dropbox\WorkbookReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxPlanningEmployeeEvidenceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private DropboxConnection $connection;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T12:00:00+02:00'));
        $this->connection = DropboxConnection::create(['settings' => [...config('dropbox.defaults'), 'timezone' => 'Europe/Berlin']]);
        $this->person = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
    }

    public function test_unique_full_name_sheet_adds_provenance_without_changing_excel_or_writing_to_database(): void
    {
        $input = $this->parsed([$this->row('  MUSTER,   Erika  ', 3)]);
        $before = $input;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->annotate($input);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $evidence = $result['rows'][0]['locator']['employee_evidence'];
        $this->assertSame(['label' => '  MUSTER,   Erika  ', 'user_id' => $this->person->id,
            'basis' => PlanningEmployeeEvidence::PERSONAL_SHEET, 'sheet' => '  MUSTER,   Erika  ', 'slot' => '3'], $evidence);
        unset($result['rows'][0]['locator']['employee_evidence']);
        $this->assertSame($before, $input);
        $this->assertSame($before, $result);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $this->assertSame(0, DropboxIdentity::count());
        $this->assertSame(1, User::count());
        Queue::assertNothingPushed();
    }

    public function test_confirmed_connection_alias_and_real_profile_name_are_allowed_but_unconfirmed_and_other_connections_are_not(): void
    {
        $this->alias('Kurzname', $this->person);
        UserProfile::create(['user_id' => $this->person->id, 'first_name' => 'Erika', 'last_name' => 'von Muster']);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'Ungeprüft', 'kind' => 'employee']);
        $other = DropboxConnection::create(['settings' => config('dropbox.defaults')]);
        DropboxIdentity::create(['connection_id' => $other->id, 'alias' => 'Fremde Zuordnung', 'kind' => 'employee', 'user_id' => $this->person->id]);
        $rows = [$this->row('Kurzname', 3), $this->row('von Muster Erika', 3), $this->row('Ungeprüft', 3), $this->row('Fremde Zuordnung', 3)];
        $result = $this->annotate($this->parsed($rows));
        $this->assertSame($this->person->id, $this->evidence($result, 0)['user_id']);
        $this->assertSame($this->person->id, $this->evidence($result, 1)['user_id']);
        $this->assertNull($this->evidence($result, 2));
        $this->assertNull($this->evidence($result, 3));
    }

    public function test_duplicate_names_ambiguous_aliases_and_non_staff_never_select_a_person(): void
    {
        $second = User::factory()->create(['name' => 'Muster Erika', 'role' => 'staff', 'status' => true]);
        $this->alias('Erika Muster', $this->person);
        $this->alias('Erika, Muster', $second);
        $admin = User::factory()->create(['name' => 'Admin Beispiel', 'role' => 'admin', 'status' => true]);
        $this->alias('Verwalter', $admin);
        $result = $this->annotate($this->parsed([$this->row('Erika Muster', 3), $this->row('Admin Beispiel', 3), $this->row('Verwalter', 3)]));
        foreach ($result['rows'] as $row) {
            $this->assertArrayNotHasKey('employee_evidence', $row['locator']);
        }
    }

    public function test_a_provider_alias_blocks_an_otherwise_unique_native_staff_name_and_compound_sheet_names_are_not_split(): void
    {
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'Muster Erika', 'kind' => 'provider']);
        foreach (['Erika Muster', 'Erika Muster / Hans Beispiel', 'Erika Muster und Hans Beispiel', 'Erika Muster + Hans Beispiel'] as $sheet) {
            $result = $this->annotate($this->parsed([$this->row($sheet, 3)]));
            $this->assertNull($this->evidence($result, 0));
        }
    }

    public function test_sheet_owner_and_conflicting_or_unresolved_explicit_employees_prevent_inference_for_the_entire_sheet(): void
    {
        User::factory()->create(['name' => 'Hans Beispiel', 'role' => 'staff', 'status' => true]);
        foreach (['Hans Beispiel', 'Unbekannte Person'] as $conflict) {
            $rows = [$this->row('Übersicht', 3, [], true), $this->row('Erika Muster', 3),
                $this->row('Erika Muster', 4, ['employee' => $conflict, 'date' => '2026-09-16'])];
            $result = $this->annotate($this->parsed($rows));
            $this->assertNull($this->evidence($result, 0));
            $this->assertNull($this->evidence($result, 1));
            $this->assertSame($conflict, $result['rows'][2]['values']['employee']);
        }
    }

    public function test_one_complete_master_event_uses_exactly_one_explicit_personal_copy_with_traceable_provenance(): void
    {
        $input = $this->parsed([$this->row('Übersicht', 5, [], true), $this->row('Persönliche Dienste', 18, ['employee' => 'Muster Erika'])]);
        $result = $this->annotate($input);
        $this->assertSame(['label' => 'Muster Erika', 'user_id' => $this->person->id,
            'basis' => PlanningEmployeeEvidence::MATCHING_PERSONAL_ROW, 'sheet' => 'Persönliche Dienste', 'slot' => '18'], $this->evidence($result, 0));
        $this->assertNull($this->evidence($result, 1));
        $this->assertSame($input['rows'][0]['values'], $result['rows'][0]['values']);
        $this->assertSame($input['rows'][0]['fingerprint'], $result['rows'][0]['fingerprint']);
    }

    public function test_one_complete_master_event_can_use_the_personal_sheet_evidence_without_mutating_empty_ma_cells(): void
    {
        $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, [], true), $this->row('Erika Muster', 18)]));
        $this->assertSame(PlanningEmployeeEvidence::MATCHING_PERSONAL_ROW, $this->evidence($result, 0)['basis']);
        $this->assertSame(PlanningEmployeeEvidence::PERSONAL_SHEET, $this->evidence($result, 1)['basis']);
        $this->assertSame('', $result['rows'][0]['values']['employee']);
        $this->assertSame('', $result['rows'][1]['values']['employee']);
        $this->assertSame($result, $this->annotate($result));
    }

    public function test_a_blank_or_absent_train_reference_in_a_matching_master_prevents_one_sided_personal_inference(): void
    {
        $master = $this->row('Übersicht', 5, ['train_reference' => ''], true);
        $copy = $this->row('Erika Muster', 18, ['train_reference' => '']);
        unset($copy['values']['train_reference']);
        $input = $this->parsed([$master, $copy]);
        $result = $this->annotate($input);
        $this->assertSame($input, $result);
        $standalone = $this->annotate($this->parsed([$copy]));
        $this->assertSame(PlanningEmployeeEvidence::PERSONAL_SHEET, $this->evidence($standalone, 0)['basis']);
    }

    public function test_an_explicit_status_conflict_with_a_blank_master_keeps_both_raw_ma_cells_blank_without_one_sided_inference(): void
    {
        foreach (['draft', 'cancelled'] as $field) {
            $input = $this->parsed([$this->row('Übersicht', 5, [$field => true], true),
                $this->row('Erika Muster', 18, [$field => false])]);
            $this->assertSame($input, $this->annotate($input));
        }
    }

    public function test_duplicate_blank_masters_or_personal_copies_cannot_leave_one_sided_personal_evidence(): void
    {
        $master = $this->row('Übersicht', 5, [], true);
        $copy = $this->row('Erika Muster', 18);
        foreach ([[$master, $this->row('Übersicht', 6, [], true), $copy],
            [$master, $copy, $this->row('Erika Muster', 19)]] as $rows) {
            $input = $this->parsed($rows);
            $this->assertSame($input, $this->annotate($input));
        }
    }

    public function test_duplicate_master_or_personal_rows_and_unresolved_extra_copies_do_not_collapse_multi_person_events(): void
    {
        $master = $this->row('Übersicht', 5, [], true);
        $copy = $this->row('Erika Muster', 18);
        foreach ([[$master, $this->row('Übersicht', 6, [], true), $copy],
            [$master, $copy, $this->row('Erika Muster', 19)],
            [$master, $copy, $this->row('Andere Kopie', 19)],
            [$master, $this->row('Übersicht', 6, ['employee' => 'Erika Muster'], true), $copy]] as $rows) {
            $result = $this->annotate($this->parsed($rows));
            $this->assertNull($this->evidence($result, 0));
        }
    }

    public function test_each_missing_or_changed_event_key_field_blocks_master_inference_including_other_weeks(): void
    {
        foreach (['date' => '2026-09-22', 'starts' => '08:01', 'ends' => '10:01', 'customer' => 'Other Rail',
            'location' => 'Andere Stadt', 'role' => 'Rangierer', 'train_reference' => 'REF-2'] as $field => $different) {
            foreach (['', $different] as $value) {
                $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, [], true),
                    $this->row('Erika Muster', 18, [$field => $value])]));
                $this->assertNull($this->evidence($result, 0), $field.'='.$value);
            }
        }
        foreach (['2026-02-30', '15.09.2026'] as $date) {
            $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, ['date' => $date], true), $this->row('Erika Muster', 18, ['date' => $date])]));
            $this->assertNull($this->evidence($result, 0));
        }
    }

    public function test_different_or_invalid_status_and_conflicting_service_references_block_the_master_copy(): void
    {
        foreach ([['draft' => true], ['cancelled' => true], ['draft' => null], ['cancelled' => null],
            ['draft' => 0], ['cancelled' => 'false'], ['service_reference' => 'SERVICE-B']] as $changes) {
            $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, ['service_reference' => 'SERVICE-A'], true),
                $this->row('Erika Muster', 18, $changes)]));
            $this->assertNull($this->evidence($result, 0));
        }
        $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, ['cancelled' => true], true),
            $this->row('Erika Muster', 18, ['cancelled' => true])]));
        $this->assertNotNull($this->evidence($result, 0));
    }

    public function test_absent_personal_status_columns_are_unknown_compatible_without_changing_master_or_raw_copy(): void
    {
        foreach ([['draft'], ['cancelled'], ['draft', 'cancelled']] as $absent) {
            $master = $this->row('Übersicht', 5, ['draft' => true, 'cancelled' => true], true);
            $copy = $this->row('Erika Muster', 18, ['draft' => true, 'cancelled' => true]);
            foreach ($absent as $field) {
                unset($copy['values'][$field]);
            }
            $copy['fingerprint'] = WorkbookReader::fingerprint($copy['values']);
            $input = $this->parsed([$master, $copy]);
            $result = $this->annotate($input);
            $this->assertSame(PlanningEmployeeEvidence::MATCHING_PERSONAL_ROW, $this->evidence($result, 0)['basis']);
            foreach ([0, 1] as $index) {
                $this->assertSame($input['rows'][$index]['values'], $result['rows'][$index]['values']);
                $this->assertSame($input['rows'][$index]['fingerprint'], $result['rows'][$index]['fingerprint']);
            }
        }
    }

    public function test_unknown_personal_columns_do_not_override_an_explicit_status_conflict_or_invalid_master(): void
    {
        $master = $this->row('Übersicht', 5, ['cancelled' => true], true);
        $copy = $this->row('Erika Muster', 18, ['cancelled' => false]);
        unset($copy['values']['draft']);
        $this->assertNull($this->evidence($this->annotate($this->parsed([$master, $copy])), 0));
        unset($copy['values']['cancelled']);
        $master['values']['cancelled'] = null;
        $this->assertNull($this->evidence($this->annotate($this->parsed([$master, $copy])), 0));
    }

    public function test_inactive_people_only_receive_evidence_after_the_entire_event_including_overnight_end_is_past(): void
    {
        $this->person->update(['status' => false]);
        foreach ([['date' => '2026-09-15', 'starts' => '08:00', 'ends' => '10:00', 'allowed' => true],
            ['date' => '2026-10-23', 'starts' => '08:00', 'ends' => '12:00', 'allowed' => true],
            ['date' => '2026-10-23', 'starts' => '08:00', 'ends' => '12:01', 'allowed' => false],
            ['date' => '2026-10-23', 'starts' => '22:00', 'ends' => '06:00', 'allowed' => false],
            ['date' => '2026-10-22', 'starts' => '22:00', 'ends' => '06:00', 'allowed' => true],
            ['date' => '2026-10-24', 'starts' => '08:00', 'ends' => '10:00', 'allowed' => false],
            ['date' => '2026-02-30', 'starts' => '08:00', 'ends' => '10:00', 'allowed' => false],
            ['date' => '2026-09-15', 'starts' => '', 'ends' => '10:00', 'allowed' => false]] as $case) {
            $allowed = $case['allowed'];
            unset($case['allowed']);
            $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, $case, true), $this->row('Erika Muster', 18, $case)]));
            $this->assertSame($allowed, $this->evidence($result, 0) !== null, json_encode($case));
            $this->assertSame($allowed, $this->evidence($result, 1) !== null, json_encode($case));
        }
        $this->assertFalse($this->person->fresh()->status);
    }

    public function test_sheet_name_evidence_does_not_require_train_or_customer_but_master_propagation_does(): void
    {
        $result = $this->annotate($this->parsed([$this->row('Übersicht', 5, ['train_reference' => ''], true),
            $this->row('Erika Muster', 18, ['train_reference' => '', 'customer' => ''])]));
        $this->assertNull($this->evidence($result, 0));
        $this->assertSame($this->person->id, $this->evidence($result, 1)['user_id']);
    }

    public function test_existing_ma_unknown_master_role_and_non_planning_availability_never_get_inferred_employees(): void
    {
        $explicit = $this->row('Erika Muster', 3, ['employee' => 'Erika Muster']);
        $unknown = $this->row('Erika Muster', 4);
        unset($unknown['locator']['master']);
        $availability = ['domain' => 'competencies', 'sheet' => 'Erika Muster', 'slot' => 'C3', 'values' => ['value' => 'Erika Muster arbeitet hier'],
            'locator' => ['kind' => 'availability_note', 'subject' => 'Erika Muster'], 'fingerprint' => 'unchanged'];
        $result = $this->annotate($this->parsed([$explicit, $unknown, $availability]));
        $this->assertSame([$explicit, $unknown, $availability], $result['rows']);
    }

    public function test_reannotation_rejects_stale_locator_evidence_after_an_explicit_employee_or_source_status_change(): void
    {
        $result = $this->annotate($this->parsed([$this->row('Erika Muster', 3)]));
        $this->assertNotNull($this->evidence($result, 0));
        $result['rows'][0]['values']['employee'] = 'Erika Muster';
        $this->assertNull($this->evidence($this->annotate($result), 0));
        $result['rows'][0]['values']['employee'] = '';
        $this->person->update(['status' => false]);
        $result['rows'][0]['values']['date'] = '2026-11-01';
        $this->assertNull($this->evidence($this->annotate($result), 0));
    }

    private function annotate(array $parsed): array
    {
        return app(PlanningEmployeeEvidence::class)->annotate($this->connection, $parsed);
    }

    private function evidence(array $parsed, int $index): ?array
    {
        return $parsed['rows'][$index]['locator']['employee_evidence'] ?? null;
    }

    private function parsed(array $rows): array
    {
        return ['rows' => $rows, 'sheets' => [], 'issues' => [], 'weeks' => ['2026-38'], 'coverage' => ['retained' => true], 'availability_groups' => []];
    }

    private function row(string $sheet, int $slot, array $changes = [], bool $master = false): array
    {
        $values = [...['location' => 'Synthetic City', 'date' => '2026-09-15', 'starts' => '08:00', 'ends' => '10:00',
            'employee' => '', 'customer' => 'Synthetic Rail', 'role' => 'WGM', 'train_reference' => 'REF-1', 'cancelled' => false, 'draft' => false], ...$changes];

        return ['domain' => 'planning', 'sheet' => $sheet, 'slot' => (string) $slot, 'values' => $values,
            'locator' => ['master' => $master, 'row' => $slot, 'cells' => ['employee' => 'E'.$slot]], 'fingerprint' => WorkbookReader::fingerprint($values)];
    }

    private function alias(string $label, User $person): DropboxIdentity
    {
        return DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => $label,
            'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => $label]]);
    }
}
