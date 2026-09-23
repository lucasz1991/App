<?php

namespace App\Services\Dropbox;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WorkbookReader
{
    public const PLANNING_COLUMNS = ['B' => 'location', 'C' => 'date', 'D' => 'time', 'E' => 'employee', 'F' => 'train_reference', 'G' => 'notes', 'H' => 'role', 'I' => 'customer', 'J' => 'ordered_at', 'K' => 'cancellation', 'L' => 'information', 'M' => 'billing_notes', 'N' => 'other'];

    public const HEADERS = ['B' => 'Einsatzort', 'C' => 'Datum', 'D' => 'Abf.Zeit', 'E' => 'MA', 'F' => 'Zug-Ref.Nr.', 'G' => 'Bemerkungen', 'H' => 'Einsatz als', 'I' => 'Kunde', 'J' => 'Bestellt am', 'K' => 'Storno / Tat. Abf. Zeit', 'L' => 'Infos', 'M' => 'Buchhaltungsanliegen', 'N' => 'Sonstiges'];

    public function read(string $bytes, string $profile): array
    {
        $package = new WorkbookPackage($bytes);
        $file = tempnam(sys_get_temp_dir(), 'rt-read-');
        $book = null;
        try {
            file_put_contents($file, $bytes);
            $reader = new Xlsx;
            $reader->setReadDataOnly(false);
            $reader->setIncludeCharts(false);
            if ($profile === 'weekly') {
                $names = $package->planningSheets();
                if (! $names) {
                    return ['rows' => [], 'sheets' => [], 'issues' => [['reason' => 'planning_headers_missing']], 'weeks' => []];
                }
                $reader->setLoadSheetsOnly($names);
            }
            $book = $reader->load($file);
            $rows = [];
            $sheets = [];
            $issues = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                if ($profile === 'weekly') {
                    if (in_array(self::normalize($sheet->getTitle()), ['kontaktliste extern dl', 'kontaktliste rail time', 'mitarbeiterverfügbarkeit'], true)) {
                        [$entries, $info, $warnings] = $this->matrix($sheet);
                    } else {
                        [$entries, $info, $warnings] = $this->planning($sheet);
                    }
                } else {
                    [$entries, $info, $warnings] = $this->matrix($sheet);
                }
                array_push($rows, ...$entries);
                array_push($issues, ...$warnings);
                if ($info) {
                    $sheets[$sheet->getTitle()] = $info;
                }
            }
            $weeks = array_values(array_unique(array_map(fn ($r) => app(WeekFileMatcher::class)->week($r['values']['date']), array_filter($rows, fn ($r) => $r['domain'] === 'planning' && ! empty($r['values']['date'])))));
            sort($weeks);

            return compact('rows', 'sheets', 'issues', 'weeks');
        } finally {
            $book?->disconnectWorksheets();
            @unlink($file);
        }
    }

    private function planning(Worksheet $sheet): array
    {
        $columns = [];
        $entries = [];
        $warnings = [];
        $header = null;
        $lastRow = $sheet->getHighestDataRow();
        $maxCol = min(60, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        for ($row = 1; $row <= $lastRow; $row++) {
            $candidate = [];
            $dateColumn = array_search('date', $columns, true);
            $scanHeader = ! $dateColumn || self::normalize($this->value($sheet, $dateColumn.$row)) === 'datum';
            for ($col = 1; $scanHeader && $col <= $maxCol; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $value = self::normalize($this->value($sheet, $letter.$row));
                foreach (self::HEADERS as $base => $label) {
                    if ($value === self::normalize($label)) {
                        $candidate[$letter] = self::PLANNING_COLUMNS[$base];
                    }
                }
                if (in_array($value, ['abf. zeit', 'abfzeit', 'zeit'], true)) {
                    $candidate[$letter] = 'time';
                }
                if (in_array($value, ['mitarbeiter', 'mitarbeitername'], true)) {
                    $candidate[$letter] = 'employee';
                }
            }
            if (in_array('date', $candidate, true) && in_array('location', $candidate, true)) {
                $columns = $candidate;
                $header ??= $row;

                continue;
            }
            if (! $columns) {
                continue;
            }
            $fields = [];
            $cells = [];
            $styles = [];
            foreach ($columns as $col => $field) {
                $fields[$field] = $this->value($sheet, $col.$row);
                $cells[$field] = $col.$row;
                $styles[$col] = $sheet->getCell($col.$row)->getXfIndex();
            }
            if (trim((string) ($fields['date'] ?? '')) === '') {
                continue;
            }
            $date = $this->date($fields['date']);
            if (! $date) {
                $warnings[] = ['sheet' => $sheet->getTitle(), 'row' => $row, 'reason' => 'invalid_date'];

                continue;
            }
            $fields = array_map(fn ($v) => trim((string) $v), $fields);
            $fields['date'] = $date;
            [$start, $end, $actual] = $this->times($fields['time'] ?? '');
            $fields['starts'] = $start;
            $fields['ends'] = $end;
            $fields['actual_end'] = $actual;
            $fields['draft'] = str_contains($fields['notes'] ?? '', '[ENTWURF]');
            $fields['notes'] = trim(str_replace('[ENTWURF]', '', $fields['notes'] ?? ''));
            $fields['cancelled'] = (bool) preg_match('/\bstorno\b|\bstorniert\b/iu', ($fields['cancellation'] ?? '').' '.($fields['notes'] ?? ''));
            if (isset($fields['ordered_at']) && $fields['ordered_at'] !== '') {
                $fields['ordered_at'] = $this->date($fields['ordered_at']) ?? $fields['ordered_at'];
            }
            unset($fields['time']);
            $entries[] = $this->entry('planning', $sheet->getTitle(), (string) $row, $fields, ['row' => $row, 'cells' => $cells, 'styles' => $styles, 'columns' => $columns, 'master' => str_contains(self::normalize($sheet->getTitle()), 'übersicht') || str_contains(self::normalize($sheet->getTitle()), 'uebersicht')]);
        }
        $styles = [];
        if ($header) {
            foreach ($columns as $col => $field) {
                $styles[$col] = $sheet->getCell($col.($header + 1))->getXfIndex();
            }
        }

        return [$entries, $header ? ['columns' => $columns, 'styles' => $styles, 'header' => $header, 'last_row' => $lastRow, 'master' => str_contains(self::normalize($sheet->getTitle()), 'übersicht')] : null, $warnings];
    }

    private function matrix(Worksheet $sheet): array
    {
        $title = self::normalize($sheet->getTitle());
        $entries = [];
        $warnings = [];
        $last = $sheet->getHighestDataRow();
        if ($title === 'ortskunde') {
            for ($row = 2; $row <= $last; $row++) {
                $location = $this->value($sheet, 'A'.$row);
                $person = $this->value($sheet, 'B'.$row);
                if (! $location || ! $person) {
                    continue;
                }
                $entries[] = $this->entry('competencies', $sheet->getTitle(), 'C'.$row, $this->factValue($sheet, 'C'.$row), ['row' => $row, 'cell' => 'C'.$row, 'kind' => 'local_knowledge', 'subject' => $person, 'name' => $location, 'scope' => $location]);
            }

            return [$entries, ['kind' => 'local_knowledge', 'last_row' => $last], []];
        }
        if ($title === 'mitarbeiterverfügbarkeit') {
            for ($row = 1; $row <= $last; $row++) {
                $subject = $this->value($sheet, 'B'.$row);
                if (! $subject || str_ends_with($subject, ':')) {
                    continue;
                }
                foreach (['C' => 'Wiederkehrende Verfügbarkeit', 'D' => 'Weitere Verfügbarkeitsangaben'] as $col => $name) {
                    if ($this->value($sheet, $col.$row) === '') {
                        continue;
                    }
                    $entries[] = $this->entry('competencies', $sheet->getTitle(), $col.$row, $this->factValue($sheet, $col.$row), ['row' => $row, 'cell' => $col.$row, 'kind' => 'availability_note', 'subject' => $subject, 'name' => $name, 'scope' => $name]);
                }
            }

            return [$entries, ['kind' => 'availability_note', 'last_row' => $last, 'columns' => ['C' => ['name' => 'Wiederkehrende Verfügbarkeit', 'scope' => 'Wiederkehrende Verfügbarkeit'], 'D' => ['name' => 'Weitere Verfügbarkeitsangaben', 'scope' => 'Weitere Verfügbarkeitsangaben']]], []];
        }
        $isStaff = $title === 'mitarbeiter übersicht';
        $isProvider = in_array($title, ['dienstleister übersicht', 'kontaktliste extern dl'], true);
        if ($isStaff || $isProvider) {
            $columns = $isStaff
                ? ['B' => 'last_name', 'C' => 'first_name', 'D' => 'reported_qualifications', 'E' => 'contact_email', 'F' => 'phone', 'G' => 'mobile', 'H' => 'city', 'I' => 'deployment_region', 'J' => 'birth_date', 'K' => 'birth_place', 'L' => 'nationality']
                : ['B' => 'name', 'C' => 'reported_qualifications', 'D' => 'contact_email', 'E' => 'phone', 'F' => 'mobile', 'G' => 'city', 'H' => 'deployment_region', 'I' => 'notes'];
            for ($row = 3; $row <= $last; $row++) {
                if (! is_numeric($this->value($sheet, 'A'.$row))) {
                    continue;
                }
                $values = [];
                $cells = [];
                foreach ($columns as $col => $key) {
                    $values[$key] = $key === 'birth_date' ? ($this->date($this->value($sheet, $col.$row)) ?? '') : $this->value($sheet, $col.$row);
                    $cells[$key] = $col.$row;
                }
                $subject = $isStaff ? trim($values['first_name'].' '.$values['last_name']) : $values['name'];
                if (! $subject) {
                    continue;
                }
                $entries[] = $this->entry('contacts', $sheet->getTitle(), (string) $row, $values, ['row' => $row, 'cells' => $cells, 'columns' => $columns, 'subject' => $subject, 'kind' => $isProvider ? 'provider' : 'employee']);
            }

            return [$entries, ['kind' => $isProvider ? 'provider' : 'employee', 'columns' => $columns, 'last_row' => $last], []];
        }
        $kind = match (true) {
            $title === 'freigaben-sperrungen' => 'customer_authorization', $title === 'einweisung' => 'introduction', str_starts_with($title, 'unterlagen') => 'document', default => null
        };
        if (! $kind) {
            return [[], null, []];
        }
        $labels = [];
        for ($c = 4; $c <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $group = $this->value($sheet, $col.'1');
            $sub = $this->value($sheet, $col.'2');
            if ($group === '') {
                foreach ($sheet->getMergeCells() as $range) {
                    [$from, $to] = Coordinate::rangeBoundaries($range);
                    if ($from[1] === 1 && $to[1] === 1 && $c >= $from[0] && $c <= $to[0]) {
                        $group = $this->value($sheet, Coordinate::stringFromColumnIndex($from[0]).'1');
                        break;
                    }
                }
            }
            if ($group !== '') {
                $labels[$col] = ['name' => trim($group.($sub ? ' / '.$sub : '')), 'scope' => $group];
            }
        }
        for ($row = 3; $row <= $last; $row++) {
            $lastName = $this->value($sheet, 'B'.$row);
            $firstName = $this->value($sheet, 'C'.$row);
            if ($lastName === '' || $firstName === '' || self::normalize($lastName) === 'nachname') {
                continue;
            }
            foreach ($labels as $col => $label) {
                $entries[] = $this->entry('competencies', $sheet->getTitle(), $col.$row, $this->factValue($sheet, $col.$row), ['row' => $row, 'cell' => $col.$row, 'kind' => $kind, 'subject' => trim($firstName.' '.$lastName), ...$label]);
            }
        }

        return [$entries, ['kind' => $kind, 'columns' => $labels, 'last_row' => $last], $warnings];
    }

    private function factValue(Worksheet $sheet, string $address): array
    {
        $cell = $sheet->getCell($address);
        $value = $this->value($sheet, $address);
        if ($value !== '' && is_numeric($value) && Date::isDateTime($cell)) {
            $value = $this->date($value) ?? $value;
        }
        $fill = $sheet->getStyle($address)->getFill();
        $color = $fill->getFillType() === 'solid' ? strtoupper($fill->getStartColor()->getARGB()) : null;

        return ['value' => $value, 'color' => $color];
    }

    private function entry(string $domain, string $sheet, string $slot, array $values, array $locator): array
    {
        return ['domain' => $domain, 'sheet' => $sheet, 'slot' => $slot, 'values' => $values, 'locator' => $locator, 'fingerprint' => self::fingerprint($values)];
    }

    public static function fingerprint(array $values): string
    {
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function identityHash(array $values): string
    {
        return self::fingerprint(array_intersect_key($values, array_flip(['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'])));
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function value(Worksheet $sheet, string $address): string
    {
        $cell = $sheet->getCell($address);
        // Never execute uploaded formulas or external workbook references.
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        return trim((string) $value);
    }

    public function date(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value > 10000 && (float) $value < 100000) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!d.m.Y', '!d.m.y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim((string) $value));
                if ($date && $date->format(substr($format, 1)) === trim((string) $value)) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function times(string $value): array
    {
        $value = preg_replace('/\s+|Uhr/u', '', str_replace(['–', '—', '−'], '-', $value));
        if (! preg_match('/^(?<start>[0-2]?\d[:.][0-5]\d)-(?<end>[0-2]?\d[:.][0-5]\d)(?:\/-?(?<actual>[0-2]?\d[:.][0-5]\d))?$/D', $value, $m)) {
            return [null, null, null];
        }
        $format = function ($v) {
            if (! $v) {
                return null;
            } [$h, $min] = preg_split('/[:.]/', $v);

            return (int) $h < 24 ? sprintf('%02d:%02d', $h, $min) : null;
        };

        return [$format($m['start']), $format($m['end']), $format($m['actual'] ?? null)];
    }
}
