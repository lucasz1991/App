<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Livewire\Admin\DropboxSettings;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxSourceResultOverviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private DropboxConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        (require database_path('migrations/2026_10_11_070000_add_last_report_to_dropbox_sources.php'))->up();
        config(['dropbox.lock_store' => 'array']);
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'settings' => config('dropbox.defaults')]);
    }

    public function test_report_distinguishes_saved_records_from_processed_excel_entries_and_shows_missing_data(): void
    {
        $source = $this->source([
            'schema' => 1, 'generated_at' => '2026-10-11T06:00:00Z', 'rev' => 'r1',
            'preview' => false, 'complete' => true, 'processed' => 203, 'total' => 203,
            'detected' => ['planning' => 155, 'availability_notes' => 48, 'availability_cells' => 86, 'availability_submissions' => 15],
            'stored' => ['planning' => 149, 'orders' => 140, 'availability_notes' => 46, 'availability_cells' => 84, 'availability_submissions' => 14, 'assigned_shifts' => 145, 'unassigned_shifts' => 4],
            'missing' => ['missing_employee' => 4, 'invalid_date' => 1, 'employee_unresolved' => 1],
            'missing_employee' => 4, 'employee_references' => 43, 'matched_employees' => 41, 'matched_providers' => 1,
            'identity_review' => [['name' => 'Nicht zugeordnete Person', 'reason' => 'employee_unresolved']],
            'pending_export' => 2, 'returned' => 11,
            'ignored_sheets' => [['sheet' => 'Wagenliste', 'reason' => 'unknown_layout']],
            'issue_examples' => [['sheet' => 'Disposition', 'row' => 9, 'reason' => 'invalid_date']],
        ]);

        $component = Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('Erkannte Quellen und Übernahme')
            ->assertSee('203 / 203 Excel-Einträge')
            ->assertSee('140 zugehörige Aufträge')
            ->assertSee('145 Schichten mit Mitarbeiterzuordnung')
            ->assertSee('4 ohne Mitarbeiterzuordnung')
            ->assertSee('Die Aufteilung in aktive Planung und Historie folgt beim nächsten Abgleich.')
            ->assertDontSee('Aktive Planung:')
            ->assertSee('Mitarbeiterangabe fehlt in der Einsatzzeile')
            ->assertSee('Ungültiges Datum in Excel')
            ->assertSee('Wagenliste')->assertSee('Blattaufbau nicht erkannt')
            ->assertSee('Zeile 9')->assertSee('2 offen')->assertSee('11 durchgeführt')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false)
            ->assertDontSee('@foreach', false)->assertDontSee('@endforeach', false)
            ->assertSee('43 Personen in der Verfügbarkeitsliste')->assertSee('86 erkannt, 84 gespeichert')
            ->assertSee('41 Mitarbeiterkonten erkannt')->assertSee('1 Dienstleister erkannt')->assertSee('1 Zuordnung offen')
            ->assertSee('Nicht zugeordnete Person')->assertSee('Mitarbeiter noch keinem Konto zugeordnet')
            ->assertSee('11.10.2026 08:00');

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
        $xpath = new \DOMXPath($dom);
        $values = $xpath->query('//*[@data-dropbox-source-result="'.$source->id.'"]//tr[@data-dropbox-report-domain="planning"]/td');
        $this->assertSame(['155', '149'], array_map(static fn ($node): string => trim($node->textContent), iterator_to_array($values)));
        $values = $xpath->query('//*[@data-dropbox-source-result="'.$source->id.'"]//tr[@data-dropbox-report-domain="availability_notes"]/td');
        $this->assertSame(['48', '46'], array_map(static fn ($node): string => trim($node->textContent), iterator_to_array($values)));
        $values = $xpath->query('//*[@data-dropbox-source-result="'.$source->id.'"]//tr[@data-dropbox-report-domain="availability_submissions"]/td');
        $this->assertSame(['15', '14'], array_map(static fn ($node): string => trim($node->textContent), iterator_to_array($values)));
        $this->assertStringContainsString('wire:poll.15s.visible="refreshStatus"', $component->html());
    }

    public function test_sources_without_a_report_do_not_claim_a_successful_or_empty_import(): void
    {
        $this->source();

        Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('Noch kein Übernahmebericht vorhanden.')
            ->assertDontSee('Keine offenen Datenhinweise in diesem Prüfstand.')
            ->assertDontSee('zugehörige Aufträge');
    }

    public function test_historical_imports_remain_saved_when_no_active_assignment_is_expected(): void
    {
        $this->source([
            'schema' => 1, 'rev' => 'r1', 'complete' => true, 'processed' => 476, 'total' => 476,
            'detected' => ['planning' => 476],
            'stored' => [
                'planning' => 476, 'orders' => 320, 'assigned_shifts' => 0, 'unassigned_shifts' => 476,
                'active_shifts' => 0, 'active_assigned_shifts' => 0, 'unassigned_active_shifts' => 0,
                'completed_shifts' => 470, 'cancelled_shifts' => 6,
                'recognized_employee_shifts' => 438, 'historical_recognized_shifts' => 432, 'historical_assigned_shifts' => 430,
            ],
            'planning_employee_references' => 30, 'planning_matched_employees' => 28, 'planning_matched_providers' => 1,
            'employee_references' => 43, 'matched_employees' => 41, 'matched_providers' => 1,
        ]);

        Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('320 zugehörige Aufträge')->assertSee('476 gespeicherte Schichten')
            ->assertSee('Aktive Planung: 0 Schichten')->assertSee('0 ohne aktive Mitarbeiterzuordnung')
            ->assertSee('470 abgeschlossene Schichten')->assertSee('432 mit erkanntem Mitarbeiterbeleg')
            ->assertSee('430 mit historischer Mitarbeiterzuordnung')->assertSee('6 Schichten storniert')
            ->assertSee('Abgeschlossene und stornierte Schichten sind gespeichert und zählen nicht zum offenen Verteilungsbedarf.')
            ->assertSee('Einsatzzeilen: 30 Personen- oder Dienstleisterbezeichnungen')->assertSee('28 Mitarbeiterkonten erkannt')
            ->assertSee('Verfügbarkeitsliste: 41 Mitarbeiterkonten erkannt')
            ->assertDontSee('476 ohne Mitarbeiterzuordnung')->assertDontSee('Bisheriger Prüfstand:')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false);
    }

    public function test_partial_and_outdated_reports_remain_visible_and_refresh_without_external_work(): void
    {
        $source = $this->source([
            'schema' => 1, 'rev' => 'old-revision', 'complete' => false,
            'processed' => 40, 'total' => 203,
            'detected' => ['planning' => 155], 'stored' => ['planning' => 30, 'orders' => 30],
        ]);
        $source->update(['state' => 'processing']);

        $component = Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('Teilstand – die Verarbeitung läuft weiter')
            ->assertSee('Neuere Dateiversion wartet auf den Abgleich')
            ->assertSee('40 / 203 Excel-Einträge');
        $this->assertStringContainsString('wire:poll.15s.visible="refreshStatus"', $component->html());

        DB::table('dropbox_sources')->where('id', $source->id)->update(['last_report' => json_encode([
            'schema' => 1, 'rev' => 'r1', 'complete' => true, 'processed' => 203, 'total' => 203,
            'detected' => ['planning' => 155], 'stored' => ['planning' => 155, 'orders' => 155],
        ])]);
        $component->call('refreshStatus')->assertSee('155 zugehörige Aufträge')->assertDontSee('Teilstand – die Verarbeitung läuft weiter');
        $this->assertSame(0, DB::table('dropbox_work_items')->count());
        $this->assertSame(0, DB::table('dropbox_runs')->count());
    }

    public function test_report_details_escape_workbook_content_and_are_inaccessible_to_an_ordinary_admin(): void
    {
        $this->source([
            'schema' => 1, 'complete' => true,
            'ignored_sheets' => [['sheet' => '<script>alert("sheet")</script>', 'reason' => 'unknown_layout']],
            'identity_review' => [['name' => '<script>alert("person")</script>', 'reason' => 'employee_unresolved']],
        ]);
        $html = Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')->html();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("sheet")</script>', $html);
        $this->assertStringNotContainsString('<script>alert("person")</script>', $html);

        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        Livewire::actingAs($admin)->test(DropboxSettings::class)->assertForbidden();
    }

    public function test_a_failed_first_attempt_reports_the_retry_reason_without_claiming_live_processing_or_success(): void
    {
        $this->source(['error_code' => 'rate_limit', 'complete' => false, 'rev' => 'r1']);

        Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('Abgleich unterbrochen:')
            ->assertSee('Dropbox begrenzt Anfragen; erneuter Versuch folgt')
            ->assertSee('Für diesen Prüfversuch liegt noch kein Übernahmeergebnis vor.')
            ->assertDontSee('Teilstand – die Verarbeitung läuft weiter')
            ->assertDontSee('Keine offenen Datenhinweise in diesem Prüfstand.')
            ->assertDontSee('Verarbeitet:')
            ->assertDontSee('Der zuletzt gespeicherte Übernahmestand bleibt unten sichtbar.')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false);
    }

    public function test_failed_reports_keep_counts_and_render_issue_examples_without_literal_blade_directives(): void
    {
        $this->source([
            'schema' => 1, 'error_code' => 'rate_limit', 'complete' => false, 'rev' => 'r1',
            'detected' => ['planning' => 3], 'stored' => ['planning' => 2, 'orders' => 2],
            'issue_examples' => [['sheet' => 'Disposition', 'row' => 9, 'reason' => 'invalid_date']],
        ]);

        Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->set('panel', 'sources')
            ->assertSee('Abgleich unterbrochen:')
            ->assertSee('Der zuletzt gespeicherte Übernahmestand bleibt unten sichtbar.')
            ->assertSee('Disposition')->assertSee('Zeile 9')->assertSee('Ungültiges Datum in Excel')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false)
            ->assertDontSee('@foreach', false)->assertDontSee('@endforeach', false);
    }

    private function source(?array $report = null): DropboxSource
    {
        $source = DropboxSource::create([
            'connection_id' => $this->connection->id, 'file_id' => 'id:week-41',
            'path' => '/RailTime-Test/Aufträge KW 41.xlsx', 'name' => 'Aufträge KW 41.xlsx',
            'profile' => 'weekly', 'rev' => 'r1', 'weeks' => ['2026-W41'],
            'state' => 'synchronized', 'first_seen_at' => now(),
        ]);
        if ($report !== null) {
            DB::table('dropbox_sources')->where('id', $source->id)->update(['last_report' => json_encode($report)]);
        }

        return $source;
    }
}
