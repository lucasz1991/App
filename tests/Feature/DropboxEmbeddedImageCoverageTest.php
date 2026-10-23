<?php

namespace Tests\Feature;

use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkbookWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DropboxEmbeddedImageCoverageTest extends TestCase
{
    public function test_plain_workbook_reports_zero_embedded_images(): void
    {
        $bytes = $this->workbook(false);

        $this->assertSame(0, (new WorkbookPackage($bytes))->embeddedImageCount());
        $this->assertSame(0, app(WorkbookReader::class)->read($bytes, 'weekly')['coverage']['embedded_images']);
    }

    public function test_embedded_image_is_reported_and_preserved_without_becoming_a_planning_row(): void
    {
        $bytes = $this->workbook(true);
        $package = new WorkbookPackage($bytes);
        $originalHashes = $package->partHashes();
        $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');

        $this->assertSame(1, $package->embeddedImageCount());
        $this->assertSame(1, $parsed['coverage']['embedded_images']);
        $this->assertSame(1, $parsed['coverage']['planning']['rows']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame($originalHashes, $package->partHashes());

        $entry = $parsed['rows'][0];
        $output = app(WorkbookWriter::class)->write($bytes, [
            ['entry' => $entry, 'values' => [...$entry['values'], 'notes' => 'Aktualisierte Notiz']],
        ], 'weekly');
        $written = new WorkbookPackage($output);
        $after = app(WorkbookReader::class)->read($output, 'weekly');

        $this->assertSame(1, $written->embeddedImageCount());
        $this->assertSame(1, $after['coverage']['embedded_images']);
        $this->assertSame('Aktualisierte Notiz', $after['rows'][0]['values']['notes']);
        foreach ($written->partHashes() as $part => $hash) {
            if ($part !== $package->sheets()['WTU Abrechnung + Übersicht']) {
                $this->assertSame($originalHashes[$part], $hash, $part);
            }
        }
    }

    private function workbook(bool $withImage): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        foreach (WorkbookReader::HEADERS as $column => $header) {
            $sheet->setCellValue($column.'1', $header);
        }
        foreach (['B' => 'Beispielbahnhof', 'C' => '2026-10-05', 'D' => '08:00-10:00', 'E' => 'Beispielperson', 'H' => 'WGM', 'I' => 'Beispielfirma'] as $column => $value) {
            $sheet->setCellValue($column.'2', $value);
        }
        $path = tempnam(sys_get_temp_dir(), 'rt-image-book-');
        $imagePath = tempnam(sys_get_temp_dir(), 'rt-image-');
        try {
            if ($withImage) {
                file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/a0eoAAAAASUVORK5CYII='));
                $drawing = new Drawing;
                $drawing->setName('Eingebetteter Beleg');
                $drawing->setPath($imagePath);
                $drawing->setCoordinates('P3');
                $drawing->setWorksheet($sheet);
            }
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
            unlink($imagePath);
        }
    }
}
