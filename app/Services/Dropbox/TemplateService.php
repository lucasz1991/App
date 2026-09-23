<?php

namespace App\Services\Dropbox;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TemplateService
{
    /** Build a clean business template; no old shared strings, comments or people are copied. */
    public function install(string $bytes): array
    {
        $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');
        $sourceName = collect($parsed['sheets'])->filter(fn ($s) => $s['master'])->keys()->first() ?? array_key_first($parsed['sheets']);
        if (! $sourceName) {
            throw ValidationException::withMessages(['template' => 'Keine Dispositionsüberschrift erkannt.']);
        }
        $temp = tempnam(sys_get_temp_dir(), 'rt-template-');
        $book = null;
        $clean = null;
        try {
            file_put_contents($temp, $bytes);
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
            $reader->setLoadSheetsOnly([$sourceName]);
            $book = $reader->load($temp);
            $source = $book->getSheetByName($sourceName);
            $clean = new Spreadsheet;
            $sheet = $clean->getActiveSheet();
            $sheet->setTitle('WTU Abrechnung + Übersicht');
            foreach (WorkbookReader::HEADERS as $column => $label) {
                $sheet->setCellValueExplicit($column.'1', $label, DataType::TYPE_STRING);
                $sourceColumn = array_search(WorkbookReader::PLANNING_COLUMNS[$column], $parsed['sheets'][$sourceName]['columns'], true) ?: $column;
                $header = $parsed['sheets'][$sourceName]['header'];
                $sheet->getStyle($column.'1')->applyFromArray($source->getStyle($sourceColumn.$header)->exportArray());
                $sheet->getStyle($column.'2')->applyFromArray($source->getStyle($sourceColumn.($header + 1))->exportArray());
                $width = $source->getColumnDimension($sourceColumn)->getWidth();
                $sheet->getColumnDimension($column)->setWidth($width > 0 ? $width : 18);
            }
            foreach (['C', 'J'] as $col) {
                $sheet->getStyle($col.'2')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            }
            $sheet->freezePane('B2');
            $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
            $writer = new Xlsx($clean);
            $writer->setPreCalculateFormulas(false);
            $writer->save($temp);
            $output = file_get_contents($temp);
            $this->validate($output);
            $path = 'dropbox/templates/'.Str::uuid().'.xlsx';
            Storage::disk('local')->put($path, $output);

            return ['path' => $path, 'hash' => hash('sha256', $output), 'created_at' => now()->toIso8601String(), 'profile' => 'weekly'];
        } finally {
            $book?->disconnectWorksheets();
            $clean?->disconnectWorksheets();
            @unlink($temp);
        }
    }

    public function validate(string $bytes): void
    {
        $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');
        if ($parsed['rows'] || count($parsed['sheets']) !== 1 || $parsed['issues']) {
            throw new \RuntimeException('template_contains_business_data');
        }
        $package = new WorkbookPackage($bytes);
        foreach (array_keys($package->partHashes()) as $part) {
            if (preg_match('/comments|threaded|customXml|externalLink|embedding|vbaProject/i', $part)) {
                throw new \RuntimeException('template_contains_unapproved_parts');
            }
        }
    }

    public function load(?array $template): string
    {
        if (! is_array($template) || ! preg_match('#^dropbox/templates/[a-f0-9-]+\.xlsx$#D', $template['path'] ?? '')) {
            throw new \RuntimeException('template_missing');
        }
        $bytes = Storage::disk('local')->get($template['path']);
        if (! hash_equals($template['hash'], hash('sha256', $bytes))) {
            throw new \RuntimeException('template_changed');
        }
        $this->validate($bytes);

        return $bytes;
    }
}
