<?php

namespace Tests\Feature;

use App\Services\Dropbox\ThreeWayMerge;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkbookWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DropboxWorkbookEvidenceTest extends TestCase
{
    public function test_personal_copy_without_cancellation_column_does_not_report_a_cancellation_revocation(): void
    {
        $book = new Spreadsheet;
        $master = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        $this->row($master, WorkbookReader::HEADERS);
        $master->setCellValue('K2', 'Storno');
        $personal = $book->createSheet()->setTitle('Mitarbeiterblatt');
        $this->row($personal, array_intersect_key(WorkbookReader::HEADERS, array_flip(range('B', 'I'))));
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $masterValues = $parsed['rows'][0]['values'];
        $personalValues = $parsed['rows'][1]['values'];
        $this->assertTrue($masterValues['cancelled']);
        $this->assertArrayNotHasKey('cancellation', $personalValues);
        $this->assertArrayNotHasKey('cancelled', $personalValues);

        $different = array_filter($personalValues, fn ($value, $key) => ($masterValues[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        $this->assertSame([], $different);
        $baseline = [...$personalValues, 'cancelled' => false];
        $merge = app(ThreeWayMerge::class)->merge($masterValues, [['baseline' => $baseline, 'excel' => $personalValues]]);
        $this->assertTrue($merge['values']['cancelled']);
        $this->assertSame([], $merge['conflicts']);
    }

    public function test_positive_cancellation_note_remains_evidence_even_without_a_cancellation_column(): void
    {
        foreach (['Storno', 'storniert: Einsatz entfällt'] as $note) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Mitarbeiterblatt');
            $this->row($sheet, array_intersect_key(WorkbookReader::HEADERS, array_flip(range('B', 'I'))));
            $sheet->setCellValue('G2', $note);
            $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
            $this->assertTrue($parsed['rows'][0]['values']['cancelled']);
            $this->assertSame($note, $parsed['rows'][0]['values']['notes']);
        }
    }

    public function test_explicit_empty_cancellation_column_remains_clear_evidence(): void
    {
        $book = new Spreadsheet;
        $this->row($book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht'), WorkbookReader::HEADERS);
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertSame('', $parsed['rows'][0]['values']['cancellation']);
        $this->assertFalse($parsed['rows'][0]['values']['cancelled']);
    }

    public function test_missing_note_column_does_not_report_an_empty_note_or_draft_flag(): void
    {
        $book = new Spreadsheet;
        $headers = array_intersect_key(WorkbookReader::HEADERS, array_flip(['B', 'C', 'D', 'E', 'H', 'I']));
        $this->row($book->getActiveSheet()->setTitle('Einsatzplanung'), $headers);
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $values = $parsed['rows'][0]['values'];
        $this->assertArrayNotHasKey('notes', $values);
        $this->assertArrayNotHasKey('draft', $values);
        $this->assertArrayNotHasKey('cancelled', $values);
    }

    public function test_explicit_positive_cancellation_note_roundtrips_through_an_existing_personal_note_column(): void
    {
        $book = new Spreadsheet;
        $this->row($book->getActiveSheet()->setTitle('Mitarbeiterblatt'), array_intersect_key(WorkbookReader::HEADERS, array_flip(range('B', 'I'))));
        $bytes = $this->bytes($book);
        $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');
        $entry = $parsed['rows'][0];
        $values = [...$entry['values'], 'notes' => 'Storno', 'cancelled' => true];
        $cells = app(WorkbookWriter::class)->cells($entry, $values);
        $this->assertSame('Storno', $cells['G2']['value']);
        $this->assertArrayNotHasKey('K2', $cells);
        $written = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => $values]], 'weekly');
        $this->assertTrue(app(WorkbookReader::class)->read($written, 'weekly')['rows'][0]['values']['cancelled']);
    }

    public function test_fullname_contacts_use_the_actual_late_header_and_keep_absent_profile_fields_unknown(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Kontaktliste Rail Time');
        foreach (['B' => 'Name', 'C' => 'Kontakt', 'D' => 'E-Mail', 'E' => 'Position - Qualifikation'] as $column => $header) {
            $sheet->setCellValue($column.'13', $header);
        }
        foreach (['A' => 1, 'B' => 'Erika Muster', 'C' => '0123 456789', 'D' => 'erika@example.test', 'E' => 'WGM'] as $column => $value) {
            $sheet->setCellValue($column.'14', $value);
        }
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $entry = $parsed['rows'][0];
        $this->assertSame(13, $parsed['sheets']['Kontaktliste Rail Time']['header']);
        $this->assertSame('0123 456789', $entry['values']['phone']);
        $this->assertSame('WGM', $entry['values']['reported_qualifications']);
        $this->assertSame('C14', $entry['locator']['cells']['phone']);
        $this->assertSame('E14', $entry['locator']['cells']['reported_qualifications']);
        foreach (['mobile', 'city', 'deployment_region', 'notes', 'first_name', 'last_name'] as $field) {
            $this->assertArrayNotHasKey($field, $entry['values']);
        }
    }

    public function test_contact_export_roundtrip_uses_detected_phone_and_qualification_columns(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Kontaktliste Rail Time');
        foreach (['B' => 'Name', 'C' => 'Kontakt', 'D' => 'E-Mail', 'E' => 'Position - Qualifikation'] as $column => $header) {
            $sheet->setCellValue($column.'13', $header);
        }
        foreach (['B' => 'Erika Muster', 'C' => '0123 456789', 'D' => 'erika@example.test', 'E' => 'WGM'] as $column => $value) {
            $sheet->setCellValue($column.'14', $value);
        }
        $bytes = $this->bytes($book);
        $entry = app(WorkbookReader::class)->read($bytes, 'weekly')['rows'][0];
        $values = [...$entry['values'], 'phone' => '0987 654321', 'reported_qualifications' => 'WGM, RB'];
        $cells = app(WorkbookWriter::class)->cells($entry, $values);
        $this->assertSame('0987 654321', $cells['C14']['value']);
        $this->assertSame('WGM, RB', $cells['E14']['value']);
        $written = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => $values]], 'weekly');
        $result = app(WorkbookReader::class)->read($written, 'weekly')['rows'][0];
        $this->assertSame($values, $result['values']);
        $this->assertArrayNotHasKey('F14', $cells);
    }

    public function test_fullname_contact_unknown_or_ambiguous_headers_are_reported_without_guessing_layout(): void
    {
        foreach (['missing', 'ambiguous'] as $case) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Kontaktliste Rail Time');
            $sheet->setCellValue('A3', 1)->setCellValue('B3', 'Erika Muster')->setCellValue('C3', '0123 456789');
            if ($case === 'ambiguous') {
                $sheet->setCellValue('B1', 'Name')->setCellValue('C1', 'Kontakt')->setCellValue('D1', 'E-Mail')->setCellValue('E1', 'Telefon');
            }
            $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
            $this->assertSame([], $parsed['rows']);
            $this->assertSame('contact_headers_'.$case, $parsed['issues'][0]['reason']);
            $this->assertSame(0, $parsed['coverage']['sheets_recognized']);
        }
    }

    public function test_weekly_provider_list_without_notes_header_does_not_clear_matrix_notes(): void
    {
        foreach (['Kontaktliste extern DL' => false, 'Dienstleister Übersicht' => true] as $title => $hasNotesHeader) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle($title);
            $sheet->setCellValue('B1', 'Mitarbeiter')->setCellValue('C1', 'Qualitfikationen')->setCellValue('D1', 'E-Mail');
            if ($hasNotesHeader) {
                $sheet->setCellValue('I1', 'Sonstiges');
            }
            $sheet->setCellValue('A3', 1)->setCellValue('B3', 'Externe Bahn GmbH');
            $parsed = app(WorkbookReader::class)->read($this->bytes($book), $hasNotesHeader ? 'matrix' : 'weekly');
            if ($hasNotesHeader) {
                $this->assertArrayHasKey('notes', $parsed['rows'][0]['values']);
            } else {
                $this->assertArrayNotHasKey('notes', $parsed['rows'][0]['values']);
                $this->assertArrayNotHasKey('notes', $parsed['rows'][0]['locator']['cells']);
            }
        }
    }

    private function row(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $column => $header) {
            $sheet->setCellValue($column.'1', $header);
        }
        foreach (['B' => 'Bahnhof', 'C' => '2026-09-14', 'D' => '08:00-16:00', 'E' => 'Erika Muster', 'H' => 'WGM', 'I' => 'Kunde'] as $column => $value) {
            $sheet->setCellValue($column.'2', $value);
        }
    }

    private function bytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt-workbook-evidence-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
