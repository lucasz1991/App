<?php

namespace App\Services\Dropbox;

use DOMDocument;
use DOMElement;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/** Visible optional service columns; source cells and existing business columns are never repurposed. */
class WorkbookServiceMetadata
{
    public const HEADERS = [
        'service_reference' => 'Leistungs-ID',
        'service_title' => 'Leistungstitel',
        'service_type' => 'Leistungsart',
        'service_location' => 'Leistungsort',
    ];

    public static function field(string $header): ?string
    {
        return match (WorkbookReader::normalize($header)) {
            'leistungs-id', 'leistungs id', 'leistungsreferenz', 'auftragsnummer', 'auftrags-nr.', 'auftrags-nr', 'auftrags-id' => 'service_reference',
            'leistungstitel', 'auftragstitel' => 'service_title',
            'leistungsart', 'auftragsart' => 'service_type',
            'leistungsort', 'auftragsort' => 'service_location',
            default => null,
        };
    }

    /** Return changes with complete output locators; callers retain these locators in their upload manifest. */
    public function prepare(WorkbookPackage $package, array $changes): array
    {
        $requested = [];
        foreach ($changes as $change) {
            if (($change['entry']['domain'] ?? null) !== 'planning') {
                continue;
            }
            foreach (array_intersect(array_keys($change['values']), array_keys(self::HEADERS)) as $field) {
                $requested[$change['entry']['sheet']][$field] = true;
            }
        }
        if (! $requested) {
            return $changes;
        }
        $strings = [];
        try {
            foreach ($package->xml('xl/sharedStrings.xml')->getElementsByTagNameNS(WorkbookPackage::NS, 'si') as $item) {
                $strings[] = $item->textContent;
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'missing_xlsx_part') {
                throw $exception;
            }
        }
        $maps = [];
        $headerChanges = [];
        foreach ($requested as $sheet => $fields) {
            $part = $package->sheets()[$sheet] ?? throw new RuntimeException('sheet_missing');
            $document = $package->xml($part);
            [$headers, $occupied, $lastColumn] = $this->inventory($document, $strings);
            if (! $headers) {
                throw new RuntimeException('planning_headers_missing');
            }
            $map = [];
            $newColumns = [];
            foreach ($headers as $header) {
                foreach ($header['services'] as $field => $column) {
                    if (isset($map[$field]) && $map[$field] !== $column) {
                        throw new RuntimeException('service_columns_inconsistent');
                    }
                    $map[$field] = $column;
                }
            }
            if (count($map) !== count(array_unique($map))) {
                throw new RuntimeException('service_columns_inconsistent');
            }
            foreach (array_keys(self::HEADERS) as $field) {
                if (! isset($fields[$field]) || isset($map[$field])) {
                    continue;
                }
                if (++$lastColumn > 60) {
                    throw new RuntimeException('service_columns_unavailable');
                }
                $map[$field] = Coordinate::stringFromColumnIndex($lastColumn);
                $newColumns[$field] = $map[$field];
            }
            $headerRows = array_keys($headers);
            foreach ($headers as $row => $header) {
                $position = array_search($row, $headerRows, true);
                $nextHeader = $headerRows[$position + 1] ?? PHP_INT_MAX;
                foreach (array_keys($fields) as $field) {
                    $column = $map[$field];
                    if (isset($header['services'][$field])) {
                        continue;
                    }
                    foreach ($occupied[$column] ?? [] as $occupiedRow => $used) {
                        if ($occupiedRow >= $row && $occupiedRow < $nextHeader) {
                            throw new RuntimeException('service_header_collision');
                        }
                    }
                    $headerChanges[$sheet][$column.$row] = ['value' => self::HEADERS[$field], 'style' => $header['style']];
                }
            }
            foreach ($newColumns as $field => $column) {
                $this->showColumn($document, Coordinate::columnIndexFromString($column), in_array($field, ['service_title', 'service_location'], true) ? 28 : 22);
            }
            if ($newColumns) {
                $package->setXml($part, $document);
            }
            $maps[$sheet] = ['columns' => array_intersect_key($map, $fields), 'headers' => $headers];
        }
        $package->patch($headerChanges);
        foreach ($changes as &$change) {
            $entry = &$change['entry'];
            $sheet = $entry['sheet'];
            if (($entry['domain'] ?? null) !== 'planning' || ! isset($maps[$sheet])) {
                unset($entry);

                continue;
            }
            $row = (int) ($entry['locator']['row'] ?? $entry['slot']);
            $header = null;
            foreach ($maps[$sheet]['headers'] as $headerRow => $candidate) {
                if ($headerRow < $row) {
                    $header = $candidate;
                }
            }
            if (! $header) {
                throw new RuntimeException('planning_headers_missing');
            }
            foreach ($maps[$sheet]['columns'] as $field => $column) {
                if (! array_key_exists($field, $change['values'])) {
                    continue;
                }
                $entry['locator']['cells'][$field] = $column.$row;
                $entry['locator']['columns'][$column] = $field;
                $locationColumn = preg_replace('/\d+$/D', '', $entry['locator']['cells']['location'] ?? 'B');
                $entry['locator']['styles'][$column] ??= $entry['locator']['styles'][$locationColumn] ?? $header['data_style'];
            }
            unset($entry);
        }
        unset($change);

        return $changes;
    }

    private function inventory(DOMDocument $document, array $strings): array
    {
        $headers = [];
        $occupied = [];
        $lastColumn = 0;
        $data = $document->getElementsByTagNameNS(WorkbookPackage::NS, 'sheetData')->item(0);
        for ($row = $data?->firstChild; $row !== null; $row = $row->nextSibling) {
            if (! $row instanceof DOMElement || $row->localName !== 'row') {
                continue;
            }
            $labels = [];
            $services = [];
            $rowNumber = (int) $row->getAttribute('r');
            $style = 0;
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'c' || ! preg_match('/^([A-Z]+)([1-9]\d*)$/D', $cell->getAttribute('r'), $address)) {
                    continue;
                }
                $column = $address[1];
                $value = $this->value($cell, $strings);
                if ($value !== '' || $cell->getElementsByTagNameNS(WorkbookPackage::NS, 'f')->length) {
                    $occupied[$column][$rowNumber] = true;
                    $lastColumn = max($lastColumn, Coordinate::columnIndexFromString($column));
                }
                $label = WorkbookReader::normalize($value);
                $labels[$column] = $label;
                if ($label === 'einsatzort') {
                    $style = (int) $cell->getAttribute('s');
                }
                if ($field = self::field($value)) {
                    if (isset($services[$field])) {
                        $services[$field] = false;
                    } else {
                        $services[$field] = $column;
                    }
                }
            }
            if (in_array('einsatzort', $labels, true) && in_array('datum', $labels, true)) {
                if (in_array(false, $services, true)) {
                    throw new RuntimeException('service_headers_ambiguous');
                }
                foreach ($services as $column) {
                    if (Coordinate::columnIndexFromString($column) > 60) {
                        throw new RuntimeException('service_columns_unavailable');
                    }
                }
                $headers[$rowNumber] = ['services' => $services, 'style' => $style, 'data_style' => 0];
            }
        }
        foreach ($document->getElementsByTagNameNS(WorkbookPackage::NS, 'mergeCell') as $merge) {
            [, $end] = Coordinate::rangeBoundaries($merge->getAttribute('ref'));
            $lastColumn = max($lastColumn, $end[0]);
        }
        ksort($headers);

        return [$headers, $occupied, $lastColumn];
    }

    private function value(DOMElement $cell, array $strings): string
    {
        $value = $cell->getElementsByTagNameNS(WorkbookPackage::NS, 'v')->item(0)?->textContent ?? '';
        if ($cell->getAttribute('t') === 's') {
            return $strings[(int) $value] ?? '';
        }
        if ($cell->getAttribute('t') === 'inlineStr') {
            return $cell->getElementsByTagNameNS(WorkbookPackage::NS, 'is')->item(0)?->textContent ?? '';
        }

        return $value;
    }

    private function showColumn(DOMDocument $document, int $index, int $width): void
    {
        $columns = $document->getElementsByTagNameNS(WorkbookPackage::NS, 'cols')->item(0);
        if (! $columns) {
            $columns = $document->createElementNS(WorkbookPackage::NS, 'cols');
            $document->documentElement->insertBefore($columns, $document->getElementsByTagNameNS(WorkbookPackage::NS, 'sheetData')->item(0));
        }
        for ($node = $columns->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;
            if (! $node instanceof DOMElement || $node->localName !== 'col') {
                continue;
            }
            $from = (int) $node->getAttribute('min');
            $to = (int) $node->getAttribute('max');
            if ($index < $from || $index > $to) {
                continue;
            }
            if ($from < $index) {
                $before = $node->cloneNode(true);
                $before->setAttribute('max', (string) ($index - 1));
                $columns->insertBefore($before, $node);
            }
            if ($to > $index) {
                $after = $node->cloneNode(true);
                $after->setAttribute('min', (string) ($index + 1));
                $columns->insertBefore($after, $node);
            }
            $columns->removeChild($node);
        }
        $column = $document->createElementNS(WorkbookPackage::NS, 'col');
        foreach (['min' => $index, 'max' => $index, 'width' => $width, 'customWidth' => 1, 'hidden' => 0] as $key => $value) {
            $column->setAttribute($key, (string) $value);
        }
        $before = null;
        foreach ($columns->childNodes as $candidate) {
            if ($candidate instanceof DOMElement && $candidate->localName === 'col' && (int) $candidate->getAttribute('min') > $index) {
                $before = $candidate;

                break;
            }
        }
        $columns->insertBefore($column, $before);
    }
}
