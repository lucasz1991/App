<?php

namespace App\Services\Dropbox;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;
use ZipArchive;

/** A targeted OOXML editor: unrelated zip members are never re-serialized. */
class WorkbookPackage
{
    public const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private array $original = [];

    private array $parts = [];

    private array $changed = [];

    public function __construct(string $bytes)
    {
        if (strlen($bytes) > config('dropbox.max_file_bytes')) {
            throw new RuntimeException('file_too_large');
        }
        $path = tempnam(sys_get_temp_dir(), 'rt-xlsx-');
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('invalid_xlsx');
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($zip->numFiles > 10000 || $size > config('dropbox.max_uncompressed_bytes') || str_contains($stat['name'], '..') || str_contains($stat['name'], '\\')) {
                    throw new RuntimeException('unsafe_xlsx');
                }
                $this->parts[$stat['name']] = $zip->getFromIndex($i);
            }
            $zip->close();
            if (! isset($this->parts['xl/workbook.xml'], $this->parts['[Content_Types].xml'])) {
                throw new RuntimeException('invalid_xlsx');
            }
            $this->original = $this->parts;
        } finally {
            @unlink($path);
        }
    }

    public function xml(string $part): DOMDocument
    {
        $bytes = $this->parts[$part] ?? throw new RuntimeException('missing_xlsx_part');
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) {
            throw new RuntimeException('unsafe_xml');
        }
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        if (! $doc->loadXML($bytes, LIBXML_NONET)) {
            throw new RuntimeException('invalid_xml');
        }

        return $doc;
    }

    public function setXml(string $part, DOMDocument $xml): void
    {
        $this->parts[$part] = $xml->saveXML();
        $this->changed[$part] = true;
    }

    public function sheets(): array
    {
        $rels = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->documentElement->childNodes as $rel) {
            if ($rel instanceof DOMElement) {
                $rels[$rel->getAttribute('Id')] = ltrim(str_starts_with($rel->getAttribute('Target'), '/') ? $rel->getAttribute('Target') : 'xl/'.$rel->getAttribute('Target'), '/');
            }
        }
        $sheets = [];
        foreach ($this->xml('xl/workbook.xml')->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            $sheets[$sheet->getAttribute('name')] = $rels[$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')];
        }

        return $sheets;
    }

    public function planningSheets(): array
    {
        $strings = [];
        if (isset($this->parts['xl/sharedStrings.xml'])) {
            foreach ($this->xml('xl/sharedStrings.xml')->getElementsByTagNameNS(self::NS, 'si') as $item) {
                $strings[] = $item->textContent;
            }
        }
        $names = [];
        foreach ($this->sheets() as $name => $part) {
            if (in_array(WorkbookReader::normalize($name), ['kontaktliste extern dl', 'kontaktliste rail time', 'mitarbeiterverfügbarkeit'], true)) {
                $names[] = $name;

                continue;
            }
            $doc = $this->xml($part);
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('s', self::NS);
            foreach ($xp->query('/s:worksheet/s:sheetData/s:row[number(@r) <= 50]') as $row) {
                $labels = [];
                foreach ($row->childNodes as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }
                    $value = $cell->getElementsByTagNameNS(self::NS, 'v')->item(0)?->textContent ?? $cell->getElementsByTagNameNS(self::NS, 'is')->item(0)?->textContent ?? '';
                    if ($cell->getAttribute('t') === 's') {
                        $value = $strings[(int) $value] ?? '';
                    }
                    $labels[] = WorkbookReader::normalize($value);
                }
                if (in_array('datum', $labels, true) && in_array('einsatzort', $labels, true)) {
                    $names[] = $name;
                    break;
                }
            }
        }

        return $names;
    }

    /** Cells: [sheet => [A1 => ['value' => scalar, 'color' => ?ARGB, 'style' => ?int]]]. */
    public function patch(array $sheets): void
    {
        $paths = $this->sheets();
        foreach ($sheets as $sheet => $cells) {
            $path = $paths[$sheet] ?? throw new RuntimeException('sheet_missing');
            $doc = $this->xml($path);
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('s', self::NS);
            $data = $doc->getElementsByTagNameNS(self::NS, 'sheetData')->item(0);
            foreach ($cells as $address => $change) {
                if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $address, $m) || (int) $m[2] > 1048576) {
                    throw new RuntimeException('invalid_cell');
                }
                $row = $xp->query('/s:worksheet/s:sheetData/s:row[@r="'.$m[2].'"]')->item(0);
                if (! $row) {
                    $row = $doc->createElementNS(self::NS, 'row');
                    $row->setAttribute('r', $m[2]);
                    $before = null;
                    foreach ($data->childNodes as $candidate) {
                        if ($candidate instanceof DOMElement && (int) $candidate->getAttribute('r') > (int) $m[2]) {
                            $before = $candidate;
                            break;
                        }
                    }
                    $data->insertBefore($row, $before);
                }
                $cell = $xp->query('s:c[@r="'.$address.'"]', $row)->item(0);
                if ($cell && $cell->getElementsByTagNameNS(self::NS, 'f')->length) {
                    throw new RuntimeException('formula_target_requires_review');
                }
                if (! $cell) {
                    $cell = $doc->createElementNS(self::NS, 'c');
                    $cell->setAttribute('r', $address);
                    $before = null;
                    $index = Coordinate::columnIndexFromString($m[1]);
                    foreach ($row->childNodes as $candidate) {
                        if ($candidate instanceof DOMElement && preg_match('/^([A-Z]+)/', $candidate->getAttribute('r'), $cm) && Coordinate::columnIndexFromString($cm[1]) > $index) {
                            $before = $candidate;
                            break;
                        }
                    }
                    $row->insertBefore($cell, $before);
                }
                if (isset($change['style'])) {
                    $cell->setAttribute('s', (string) $change['style']);
                }
                if (array_key_exists('color', $change)) {
                    $cell->setAttribute('s', (string) $this->colorStyle((int) $cell->getAttribute('s'), $change['color']));
                }
                while ($cell->firstChild) {
                    $cell->removeChild($cell->firstChild);
                }
                $value = $change['value'] ?? '';
                if (is_int($value) || is_float($value)) {
                    $cell->removeAttribute('t');
                    $cell->appendChild($doc->createElementNS(self::NS, 'v', (string) $value));
                } else {
                    // Inline strings also prevent formula injection from names/notes.
                    $cell->setAttribute('t', 'inlineStr');
                    $is = $doc->createElementNS(self::NS, 'is');
                    $text = $doc->createElementNS(self::NS, 't');
                    $text->setAttribute('xml:space', 'preserve');
                    $text->appendChild($doc->createTextNode((string) $value));
                    $is->appendChild($text);
                    $cell->appendChild($is);
                }
            }
            $dimension = $doc->getElementsByTagNameNS(self::NS, 'dimension')->item(0);
            if ($dimension) {
                [$min, $max] = Coordinate::rangeBoundaries($dimension->getAttribute('ref') ?: 'A1');
                foreach (array_keys($cells) as $address) {
                    [$col, $row] = Coordinate::coordinateFromString($address);
                    $column = Coordinate::columnIndexFromString($col);
                    $min = [min($min[0], $column), min($min[1], $row)];
                    $max = [max($max[0], $column), max($max[1], $row)];
                }
                $dimension->setAttribute('ref', Coordinate::stringFromColumnIndex($min[0]).$min[1].':'.Coordinate::stringFromColumnIndex($max[0]).$max[1]);
            }
            $this->setXml($path, $doc);
        }
    }

    private function colorStyle(int $style, ?string $argb): int
    {
        if ($argb !== null && ! preg_match('/^[0-9A-F]{8}$/D', $argb)) {
            throw new RuntimeException('invalid_fill');
        }
        $doc = $this->xml('xl/styles.xml');
        $fills = $doc->getElementsByTagNameNS(self::NS, 'fills')->item(0);
        $xfs = $doc->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0);
        $fillId = 0;
        if ($argb) {
            $fill = $doc->createElementNS(self::NS, 'fill');
            $pattern = $doc->createElementNS(self::NS, 'patternFill');
            $pattern->setAttribute('patternType', 'solid');
            $color = $doc->createElementNS(self::NS, 'fgColor');
            $color->setAttribute('rgb', $argb);
            $pattern->appendChild($color);
            $fill->appendChild($pattern);
            $fillId = $fills->getElementsByTagNameNS(self::NS, 'fill')->length;
            $fills->appendChild($fill);
            $fills->setAttribute('count', (string) ($fillId + 1));
        }
        $xf = $xfs->getElementsByTagNameNS(self::NS, 'xf')->item($style);
        if (! $xf) {
            throw new RuntimeException('invalid_style');
        }
        $xf = $xf->cloneNode(true);
        $xf->setAttribute('fillId', (string) $fillId);
        $xf->setAttribute('applyFill', '1');
        $id = $xfs->getElementsByTagNameNS(self::NS, 'xf')->length;
        $xfs->appendChild($xf);
        $xfs->setAttribute('count', (string) ($id + 1));
        $this->setXml('xl/styles.xml', $doc);

        return $id;
    }

    public function addEmployeeSheet(string $name, string $templateSheet, array $headers, ?string $templateBytes = null): void
    {
        if (mb_strlen($name) > 31 || preg_match('/[\\\\\/?*\[\]:]/u', $name) || isset($this->sheets()[$name])) {
            throw new RuntimeException('invalid_or_duplicate_sheet_name');
        }
        $source = $this->xml($this->sheets()[$templateSheet]);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(self::NS, 'worksheet');
        $doc->appendChild($root);
        foreach (['sheetFormatPr', 'cols'] as $tag) {
            $node = $source->getElementsByTagNameNS(self::NS, $tag)->item(0);
            if ($node) {
                $root->appendChild($doc->importNode($node, true));
            }
        }
        $root->appendChild($doc->createElementNS(self::NS, 'sheetData'));
        if ($templateBytes !== null) {
            $template = new self($templateBytes);
            $templatePaths = $template->sheets();
            $doc = $template->xml(reset($templatePaths));
            $styles = $this->importStyles($template);
            $strings = [];
            if (isset($template->parts['xl/sharedStrings.xml'])) {
                foreach ($template->xml('xl/sharedStrings.xml')->getElementsByTagNameNS(self::NS, 'si') as $si) {
                    $strings[] = $si;
                }
            }
            foreach ($doc->getElementsByTagNameNS(self::NS, 'c') as $cell) {
                $cell->setAttribute('s', (string) ($styles[(int) $cell->getAttribute('s')] ?? 0));
                if ($cell->getAttribute('t') === 's') {
                    $index = (int) $cell->getElementsByTagNameNS(self::NS, 'v')->item(0)?->textContent;
                    while ($cell->firstChild) {
                        $cell->removeChild($cell->firstChild);
                    }
                    $inline = $doc->createElementNS(self::NS, 'is');
                    foreach ($strings[$index]->childNodes as $node) {
                        $inline->appendChild($doc->importNode($node, true));
                    }
                    $cell->setAttribute('t', 'inlineStr');
                    $cell->appendChild($inline);
                }
            }
            foreach (['col' => 'style', 'row' => 's'] as $tag => $attribute) {
                foreach ($doc->getElementsByTagNameNS(self::NS, $tag) as $node) {
                    if ($node->hasAttribute($attribute)) {
                        $node->setAttribute($attribute, (string) ($styles[(int) $node->getAttribute($attribute)] ?? 0));
                    }
                }
            }
        }
        $workbook = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        $types = $this->xml('[Content_Types].xml');
        $id = 1;
        foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $s) {
            $id = max($id, (int) $s->getAttribute('sheetId') + 1);
        }
        $part = 'xl/worksheets/railtime'.$id.'.xml';
        while (isset($this->parts[$part])) {
            $part = 'xl/worksheets/railtime'.(++$id).'.xml';
        }
        $rid = 'rtSheet'.$id;
        $sheet = $workbook->createElementNS(self::NS, 'sheet');
        $sheet->setAttribute('name', $name);
        $sheet->setAttribute('sheetId', (string) $id);
        $sheet->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $rid);
        $workbook->getElementsByTagNameNS(self::NS, 'sheets')->item(0)->appendChild($sheet);
        $rel = $rels->createElementNS($rels->documentElement->namespaceURI, 'Relationship');
        foreach (['Id' => $rid, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'Target' => substr($part, 3)] as $k => $v) {
            $rel->setAttribute($k, $v);
        }
        $rels->documentElement->appendChild($rel);
        $type = $types->createElementNS($types->documentElement->namespaceURI, 'Override');
        $type->setAttribute('PartName', '/'.$part);
        $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
        $types->documentElement->appendChild($type);
        $this->setXml($part, $doc);
        $this->setXml('xl/workbook.xml', $workbook);
        $this->setXml('xl/_rels/workbook.xml.rels', $rels);
        $this->setXml('[Content_Types].xml', $types);
        $this->patch([$name => $headers]);
    }

    private function importStyles(self $template): array
    {
        $target = $this->xml('xl/styles.xml');
        $source = $template->xml('xl/styles.xml');
        $maps = [];
        foreach (['fonts' => 'font', 'fills' => 'fill', 'borders' => 'border', 'numFmts' => 'numFmt'] as $group => $tag) {
            $from = $source->getElementsByTagNameNS(self::NS, $group)->item(0);
            if (! $from) {
                continue;
            }
            $to = $target->getElementsByTagNameNS(self::NS, $group)->item(0);
            if (! $to) {
                $to = $target->createElementNS(self::NS, $group);
                $target->documentElement->insertBefore($to, $target->documentElement->firstChild);
            }
            $count = $to->getElementsByTagNameNS(self::NS, $tag)->length;
            $number = 164;
            if ($group === 'numFmts') {
                foreach ($to->childNodes as $n) {
                    if ($n instanceof DOMElement) {
                        $number = max($number, (int) $n->getAttribute('numFmtId') + 1);
                    }
                }
            }
            foreach ($from->getElementsByTagNameNS(self::NS, $tag) as $index => $node) {
                $copy = $target->importNode($node, true);
                if ($group === 'numFmts') {
                    $maps[$group][(int) $node->getAttribute('numFmtId')] = $number;
                    $copy->setAttribute('numFmtId', (string) $number++);
                } else {
                    $maps[$group][$index] = $count;
                }
                $to->appendChild($copy);
                $count++;
            }
            $to->setAttribute('count', (string) $count);
        }
        $to = $target->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0);
        $map = [];
        foreach ($source->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0)->getElementsByTagNameNS(self::NS, 'xf') as $index => $xf) {
            $copy = $target->importNode($xf, true);
            foreach (['fontId' => 'fonts', 'fillId' => 'fills', 'borderId' => 'borders', 'numFmtId' => 'numFmts'] as $attribute => $group) {
                $old = (int) $copy->getAttribute($attribute);
                $copy->setAttribute($attribute, (string) ($maps[$group][$old] ?? $old));
            }
            $copy->setAttribute('xfId', '0');
            $map[$index] = $to->getElementsByTagNameNS(self::NS, 'xf')->length;
            $to->appendChild($copy);
        }
        $to->setAttribute('count', (string) $to->getElementsByTagNameNS(self::NS, 'xf')->length);
        $this->setXml('xl/styles.xml', $target);

        return $map;
    }

    public function bytes(): string
    {
        foreach ($this->original as $part => $bytes) {
            if (! isset($this->changed[$part]) && $this->parts[$part] !== $bytes) {
                throw new RuntimeException('unrelated_part_changed');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'rt-xlsx-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($this->parts as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();
            $output = file_get_contents($path);
            $check = new self($output);
            foreach ($this->original as $part => $bytes) {
                if (! isset($this->changed[$part]) && $check->parts[$part] !== $bytes) {
                    throw new RuntimeException('package_verification_failed');
                }
            }

            return $output;
        } finally {
            @unlink($path);
        }
    }

    public function partHashes(): array
    {
        return array_map(fn ($s) => hash('sha256', $s), $this->parts);
    }
}
