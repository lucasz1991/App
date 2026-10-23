<?php

namespace Tests\Feature;

use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkbookServiceMetadata;
use App\Services\Dropbox\WorkbookWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class DropboxWorkbookServiceMetadataTest extends TestCase
{
    public function test_explicit_service_headers_keep_service_and_shift_fields_separate(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        foreach (['O' => 'Auftragsnummer', 'P' => 'Auftragstitel', 'Q' => 'Auftragsart', 'R' => 'Auftragsort'] as $column => $header) {
            $sheet->setCellValue($column.'1', $header);
        }
        foreach (['O' => '000123', 'P' => 'Gesamte Leistung', 'Q' => 'Rangierdienst', 'R' => 'Leistungsbereich'] as $column => $value) {
            $sheet->setCellValueExplicit($column.'2', $value, DataType::TYPE_STRING);
        }
        $entry = $this->read($this->bytes($book))['rows'][0];
        $this->assertSame('000123', $entry['values']['service_reference']);
        $this->assertSame('Gesamte Leistung', $entry['values']['service_title']);
        $this->assertSame('Rangierdienst', $entry['values']['service_type']);
        $this->assertSame('Leistungsbereich', $entry['values']['service_location']);
        $this->assertSame('Bahnhof', $entry['values']['location']);
        $this->assertSame('WGM', $entry['values']['role']);
        $this->assertSame('ZUG-100', $entry['values']['train_reference']);
    }

    public function test_absent_or_uninitialized_service_cells_remain_unknown_but_explicit_blank_fields_are_read_with_a_reference(): void
    {
        $book = $this->book();
        $plain = $this->read($this->bytes($book));
        $this->assertSame([], array_intersect_key($plain['rows'][0]['values'], WorkbookServiceMetadata::HEADERS));

        $book = $this->book();
        $sheet = $book->getActiveSheet();
        foreach (array_values(WorkbookServiceMetadata::HEADERS) as $index => $header) {
            $sheet->setCellValue([15 + $index, 1], $header);
        }
        $this->planningRow($sheet, 3);
        $sheet->setCellValue('O3', 'SERVICE-1');
        $parsed = $this->read($this->bytes($book));
        $this->assertSame([], array_intersect_key($parsed['rows'][0]['values'], WorkbookServiceMetadata::HEADERS));
        $this->assertSame(['service_reference' => 'SERVICE-1', 'service_title' => '', 'service_type' => '', 'service_location' => ''], array_intersect_key($parsed['rows'][1]['values'], WorkbookServiceMetadata::HEADERS));
    }

    public function test_writer_extends_all_section_headers_after_real_content_without_populating_other_chunk_rows(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $this->headers($sheet, 5);
        $this->planningRow($sheet, 6);
        $sheet->setCellValue('O3', 'Vorhandene Notiz rechts');
        $bytes = $this->bytes($book);
        $parsed = $this->read($bytes);
        $entry = $parsed['rows'][0];
        $values = [...$entry['values'], ...$this->service()];
        $result = app(WorkbookWriter::class)->writeChanges($bytes, [['entry' => $entry, 'values' => $values, 'appearance_id' => 17]], 'weekly');
        $output = $this->read($result['bytes']);
        $this->assertSame($this->service(), array_intersect_key($output['rows'][0]['values'], WorkbookServiceMetadata::HEADERS));
        $this->assertSame([], array_intersect_key($output['rows'][1]['values'], WorkbookServiceMetadata::HEADERS));
        $this->assertSame('P2', $result['changes'][0]['entry']['locator']['cells']['service_reference']);
        $this->assertSame(17, $result['changes'][0]['appearance_id']);
        $package = new WorkbookPackage($result['bytes']);
        $xml = $package->xml($package->sheets()['WTU Abrechnung + Übersicht']);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('s', WorkbookPackage::NS);
        foreach ([1, 5] as $row) {
            $this->assertSame('Leistungs-ID', $xpath->query('//s:c[@r="P'.$row.'"]/s:is/s:t')->item(0)->textContent);
            $this->assertSame('Leistungsort', $xpath->query('//s:c[@r="S'.$row.'"]/s:is/s:t')->item(0)->textContent);
        }
        $this->assertSame('Vorhandene Notiz rechts', $xpath->query('//s:c[@r="O3"]/s:is/s:t')->item(0)?->textContent ?? $this->value($package, 'O3'));
        $before = (new WorkbookPackage($bytes))->partHashes();
        foreach ($package->partHashes() as $part => $hash) {
            if ($part !== $package->sheets()['WTU Abrechnung + Übersicht']) {
                $this->assertSame($before[$part], $hash, $part);
            }
        }
    }

    public function test_new_app_row_gets_service_columns_and_returns_a_complete_persistable_locator(): void
    {
        $bytes = $this->bytes($this->book());
        $parsed = $this->read($bytes);
        $entry = $parsed['rows'][0];
        $entry['slot'] = '3';
        $entry['locator']['row'] = 3;
        foreach ($entry['locator']['cells'] as &$cell) {
            $cell = preg_replace('/\d+$/D', '3', $cell);
        }
        unset($cell, $entry['fingerprint']);
        $values = [...$entry['values'], 'date' => '2026-10-06', ...$this->service()];
        $entry['values'] = $values;
        $result = app(WorkbookWriter::class)->writeChanges($bytes, [['entry' => $entry, 'values' => $values, 'record_id' => 7]], 'weekly');
        $rows = $this->read($result['bytes'])['rows'];
        $this->assertCount(2, $rows);
        $this->assertEquals($values, $rows[1]['values']);
        $this->assertSame('O3', $result['changes'][0]['entry']['locator']['cells']['service_reference']);
        $this->assertSame('R3', $result['changes'][0]['entry']['locator']['cells']['service_location']);
        $this->assertSame(7, $result['changes'][0]['record_id']);
    }

    public function test_partial_service_write_keeps_other_optional_fields_and_existing_header_aliases(): void
    {
        $bytes = $this->bytes($this->book());
        $entry = $this->read($bytes)['rows'][0];
        $values = [...$entry['values'], ...$this->service()];
        $bytes = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => $values]], 'weekly');
        $entry = $this->read($bytes)['rows'][0];
        $values = array_diff_key($entry['values'], WorkbookServiceMetadata::HEADERS);
        $values['service_title'] = 'Geänderter Leistungstitel';
        $result = app(WorkbookWriter::class)->writeChanges($bytes, [['entry' => $entry, 'values' => $values]], 'weekly');
        $written = $this->read($result['bytes'])['rows'][0]['values'];
        $this->assertSame([...$this->service(), 'service_title' => 'Geänderter Leistungstitel'], array_intersect_key($written, WorkbookServiceMetadata::HEADERS));
    }

    public function test_unlabelled_occupied_service_column_in_another_section_is_rejected(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('O1', 'Leistungs-ID')->setCellValue('O2', 'SERVICE-1');
        $this->headers($sheet, 5);
        $this->planningRow($sheet, 6);
        $sheet->setCellValue('O6', 'Andere bestehende Information');
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $this->expectExceptionMessage('service_header_collision');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], ...$this->service()]]], 'weekly');
    }

    public function test_ambiguous_reference_headers_are_visible_and_writer_never_guesses_the_column(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('O1', 'Leistungs-ID')->setCellValue('P1', 'Auftragsnummer');
        $sheet->setCellValue('O2', 'SERVICE-1')->setCellValue('P2', 'SERVICE-2');
        $bytes = $this->bytes($book);
        $parsed = $this->read($bytes);
        $this->assertSame('service_headers_ambiguous', $parsed['issues'][0]['reason']);
        $this->assertArrayNotHasKey('service_reference', $parsed['rows'][0]['values']);
        $this->expectExceptionMessage('service_headers_ambiguous');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $parsed['rows'][0], 'values' => [...$parsed['rows'][0]['values'], ...$this->service()]]], 'weekly');
    }

    public function test_formula_columns_and_hidden_unused_columns_are_preserved_while_new_columns_are_visible(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('T20', '="bestehend"');
        $sheet->getColumnDimension('O')->setVisible(false);
        $sheet->getColumnDimension('U')->setVisible(false);
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $written = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], ...$this->service()]]], 'weekly');
        $package = new WorkbookPackage($written);
        $xml = $package->xml($package->sheets()['WTU Abrechnung + Übersicht']);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('s', WorkbookPackage::NS);
        $this->assertSame('"bestehend"', $xpath->query('//s:c[@r="T20"]/s:f')->item(0)->textContent);
        $this->assertContains($xpath->query('//s:cols/s:col[@min="15"]')->item(0)->getAttribute('hidden'), ['1', 'true']);
        foreach (range(21, 24) as $column) {
            $node = $xpath->query('//s:cols/s:col[@min="'.$column.'" and @max="'.$column.'"]')->item(0);
            $this->assertNotNull($node);
            $this->assertSame('0', $node->getAttribute('hidden'));
            $this->assertGreaterThanOrEqual(22, (int) $node->getAttribute('width'));
        }
    }

    public function test_no_room_within_reader_columns_fails_before_overwriting_any_existing_cell(): void
    {
        $book = $this->book();
        $book->getActiveSheet()->setCellValue('BH20', 'Bestehende Information');
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $this->expectExceptionMessage('service_columns_unavailable');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], ...$this->service()]]], 'weekly');
    }

    public function test_existing_service_column_cannot_write_an_invisible_merged_data_cell(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('P1', 'Leistungsort');
        $sheet->setCellValue('O2', 'Sichtbare bestehende Information');
        $sheet->mergeCells('O2:P2');
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $this->expectExceptionMessage('service_merged_target_requires_review');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], 'service_location' => 'Neuer Leistungsort']]], 'weekly');
    }

    public function test_missing_repeated_service_header_cannot_be_added_inside_a_merged_header_cell(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('P1', 'Leistungsort')->setCellValue('P2', 'Leistungsbereich');
        $this->headers($sheet, 5);
        $this->planningRow($sheet, 6);
        $sheet->setCellValue('O5', 'Andere bestehende Überschrift');
        $sheet->mergeCells('O5:P5');
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $this->expectExceptionMessage('service_merged_target_requires_review');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], 'service_location' => 'Neuer Leistungsort']]], 'weekly');
    }

    public function test_visible_anchor_of_a_merged_service_cell_can_still_be_updated(): void
    {
        $book = $this->book();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('O1', 'Leistungsort')->setCellValue('O2', 'Bisheriger Leistungsort');
        $sheet->mergeCells('O2:P2');
        $bytes = $this->bytes($book);
        $entry = $this->read($bytes)['rows'][0];
        $written = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], 'service_location' => 'Neuer Leistungsort']]], 'weekly');
        $this->assertSame('Neuer Leistungsort', $this->read($written)['rows'][0]['values']['service_location']);
    }

    public function test_clearing_every_service_field_cannot_pass_strict_readback_as_an_unknown_row(): void
    {
        $bytes = $this->bytes($this->book());
        $entry = $this->read($bytes)['rows'][0];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('written_service_unreadable:service_reference');
        app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], ...array_fill_keys(array_keys(WorkbookServiceMetadata::HEADERS), '')]]], 'weekly');
    }

    private function book(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        $this->headers($sheet, 1);
        $this->planningRow($sheet, 2);

        return $book;
    }

    private function headers(Worksheet $sheet, int $row): void
    {
        foreach (WorkbookReader::HEADERS as $column => $header) {
            $sheet->setCellValue($column.$row, $header);
        }
    }

    private function planningRow(Worksheet $sheet, int $row): void
    {
        foreach (['B' => 'Bahnhof', 'C' => '2026-10-05', 'D' => '08:00-16:00', 'E' => 'Erika Muster', 'F' => 'ZUG-100', 'H' => 'WGM', 'I' => 'Kunde'] as $column => $value) {
            $sheet->setCellValue($column.$row, $value);
        }
    }

    private function service(): array
    {
        return ['service_reference' => 'SERVICE-1', 'service_title' => 'Gesamte Leistung', 'service_type' => 'Rangierdienst', 'service_location' => 'Leistungsbereich'];
    }

    private function read(string $bytes): array
    {
        return app(WorkbookReader::class)->read($bytes, 'weekly');
    }

    private function value(WorkbookPackage $package, string $address): string
    {
        $xml = $package->xml($package->sheets()['WTU Abrechnung + Übersicht']);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('s', WorkbookPackage::NS);
        $cell = $xpath->query('//s:c[@r="'.$address.'"]')->item(0);
        $value = $cell->getElementsByTagNameNS(WorkbookPackage::NS, 'v')->item(0)?->textContent ?? '';
        if ($cell->getAttribute('t') === 's') {
            return $package->xml('xl/sharedStrings.xml')->getElementsByTagNameNS(WorkbookPackage::NS, 'si')->item((int) $value)->textContent;
        }

        return $value;
    }

    private function bytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt-service-metadata-');
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
