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
            file_put_contents($file, $package->readerBytes() ?? $bytes);
            $reader = new Xlsx;
            $reader->setReadDataOnly(false);
            $reader->setIncludeCharts(false);
            if ($profile === 'weekly') {
                $names = $package->planningSheets();
                if (! $names) {
                    return ['rows' => [], 'sheets' => [], 'issues' => [['reason' => 'planning_headers_missing']], 'weeks' => [], 'availability_groups' => [], 'coverage' => $this->coverage($package, [], [], [['reason' => 'planning_headers_missing']])];
                }
                $reader->setLoadSheetsOnly($names);
            }
            $book = $reader->load($file);
            $rows = [];
            $sheets = [];
            $issues = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                if ($profile === 'weekly') {
                    if (in_array(self::normalize($sheet->getTitle()), ['kontaktliste extern dl', 'kontaktliste rail time', 'kontaktliste railtime', 'mitarbeiterverfügbarkeit', 'app-verfügbarkeiten'], true)) {
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
            foreach ($rows as $entry) {
                if (($entry['locator']['kind'] ?? '') === 'availability_submission') {
                    array_push($weeks, ...app(NativeAvailabilityWorkbook::class)->weeks($entry['values']['from'], $entry['values']['until']));
                }
            }
            $weeks = array_values(array_unique($weeks));
            sort($weeks);

            $availabilityGroups = [];
            foreach ($sheets as $name => $info) {
                foreach ($info['ignored_groups'] ?? [] as $row) {
                    $availabilityGroups[] = ['sheet' => $name, 'row' => $row];
                }
            }
            $coverage = $this->coverage($package, $rows, $sheets, $issues);

            return ['rows' => $rows, 'sheets' => $sheets, 'issues' => $issues, 'weeks' => $weeks, 'availability_groups' => $availabilityGroups, 'coverage' => $coverage];
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
                if (trim((string) ($fields['time'] ?? '')) !== '' && trim((string) ($fields['location'] ?? '')) !== '') {
                    $warnings[] = ['sheet' => $sheet->getTitle(), 'row' => $row, 'reason' => 'planning_date_missing'];
                }

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
            if (! $start || ! $end) {
                $warnings[] = ['sheet' => $sheet->getTitle(), 'row' => $row, 'reason' => 'planning_time_missing', 'fields' => array_values(array_filter(['starts' => ! $start ? 'starts' : null, 'ends' => ! $end ? 'ends' : null])), 'retained' => true];
            }
            if (array_key_exists('notes', $fields)) {
                $fields['draft'] = str_contains($fields['notes'], '[ENTWURF]');
                $fields['notes'] = trim(str_replace('[ENTWURF]', '', $fields['notes']));
            }
            $cancelled = (bool) preg_match('/\bstorno\b|\bstorniert\b/iu', ($fields['cancellation'] ?? '').' '.($fields['notes'] ?? ''));
            // A personal copy without the cancellation column cannot revoke the master's cancellation.
            if (array_key_exists('cancellation', $fields) || $cancelled) {
                $fields['cancelled'] = $cancelled;
            }
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

        if ($header && ($missing = array_values(array_diff(['location', 'date', 'time', 'customer'], $columns)))) {
            $warnings[] = ['sheet' => $sheet->getTitle(), 'row' => $header, 'reason' => 'planning_columns_missing', 'fields' => $missing, 'retained' => true];
        }

        return [$entries, $header ? ['columns' => $columns, 'styles' => $styles, 'header' => $header, 'last_row' => $lastRow, 'master' => str_contains(self::normalize($sheet->getTitle()), 'übersicht') || str_contains(self::normalize($sheet->getTitle()), 'uebersicht')] : null, $warnings];
    }

    private function matrix(Worksheet $sheet): array
    {
        $title = self::normalize($sheet->getTitle());
        $entries = [];
        $warnings = [];
        $last = $sheet->getHighestDataRow();
        if ($title === 'app-verfügbarkeiten') {
            return app(NativeAvailabilityWorkbook::class)->read($sheet);
        }
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
            $ignoredGroups = [];
            foreach ($sheet->getMergeCells() as $range) {
                [$from, $to] = Coordinate::rangeBoundaries($range);
                if ($from[0] <= 2 && $to[0] >= 3 && $from[1] === $to[1]) {
                    $ignoredGroups[] = $from[1];
                }
            }
            $people = 0;
            $nonemptyNotes = 0;
            for ($row = 1; $row <= $last; $row++) {
                $subject = $this->value($sheet, 'B'.$row);
                if (! $subject || str_ends_with($subject, ':') || in_array($row, $ignoredGroups, true)) {
                    if ($subject && str_ends_with($subject, ':') && ! in_array($row, $ignoredGroups, true)) {
                        $ignoredGroups[] = $row;
                    }

                    continue;
                }
                $people++;
                foreach (['C' => 'Wiederkehrende Verfügbarkeit', 'D' => 'Weitere Verfügbarkeitsangaben'] as $col => $name) {
                    if ($this->value($sheet, $col.$row) !== '') {
                        $nonemptyNotes++;
                    }
                    $entries[] = $this->entry('competencies', $sheet->getTitle(), $col.$row, $this->factValue($sheet, $col.$row), ['row' => $row, 'cell' => $col.$row, 'kind' => 'availability_note', 'subject' => $subject, 'name' => $name, 'scope' => $name]);
                }
            }

            sort($ignoredGroups);

            return [$entries, ['kind' => 'availability_note', 'last_row' => $last, 'people' => $people, 'nonempty_notes' => $nonemptyNotes, 'ignored_groups' => $ignoredGroups, 'columns' => ['C' => ['name' => 'Wiederkehrende Verfügbarkeit', 'scope' => 'Wiederkehrende Verfügbarkeit'], 'D' => ['name' => 'Weitere Verfügbarkeitsangaben', 'scope' => 'Weitere Verfügbarkeitsangaben']]], []];
        }
        $isStaff = $title === 'mitarbeiter übersicht';
        $isStaffFullName = in_array($title, ['kontaktliste rail time', 'kontaktliste railtime'], true);
        $isProvider = in_array($title, ['dienstleister übersicht', 'kontaktliste extern dl'], true);
        if ($isStaffFullName) {
            return $this->fullNameContacts($sheet);
        }
        if ($isStaff || $isProvider) {
            $columns = $isStaff
                ? ['B' => 'last_name', 'C' => 'first_name', 'D' => 'reported_qualifications', 'E' => 'contact_email', 'F' => 'phone', 'G' => 'mobile', 'H' => 'city', 'I' => 'deployment_region', 'J' => 'birth_date', 'K' => 'birth_place', 'L' => 'nationality']
                : ['B' => 'name', 'C' => 'reported_qualifications', 'D' => 'contact_email', 'E' => 'phone', 'F' => 'mobile', 'G' => 'city', 'H' => 'deployment_region', 'I' => 'notes'];
            if ($title === 'kontaktliste extern dl' && trim($this->value($sheet, 'I1').' '.$this->value($sheet, 'I2')) === '') {
                unset($columns['I']);
            }
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

    private function fullNameContacts(Worksheet $sheet): array
    {
        $aliases = [
            'name' => 'name', 'mitarbeiter' => 'name', 'mitarbeitername' => 'name',
            'kontakt' => 'phone', 'telefon' => 'phone', 'telefon dienst' => 'phone',
            'e-mail' => 'contact_email', 'email' => 'contact_email',
            'position - qualifikation' => 'reported_qualifications', 'qualifikation' => 'reported_qualifications',
            'qualifikationen' => 'reported_qualifications', 'qualitfikationen' => 'reported_qualifications',
            'mobil' => 'mobile', 'mobiltelefon' => 'mobile', 'handy' => 'mobile', 'telefon privat' => 'mobile',
            'wohnort' => 'city', 'stadt' => 'city', 'einsatz in' => 'deployment_region', 'einsatzgebiet' => 'deployment_region',
            'sonstiges' => 'notes', 'bemerkungen' => 'notes', 'notiz' => 'notes',
        ];
        $last = $sheet->getHighestDataRow();
        $maxColumn = min(60, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        $columns = [];
        $header = null;
        for ($row = 1; $row <= $last; $row++) {
            $candidate = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $label = self::normalize($this->value($sheet, $letter.$row));
                if (isset($aliases[$label])) {
                    $candidate[$letter] = $aliases[$label];
                }
            }
            if (in_array('name', $candidate, true) && in_array('contact_email', $candidate, true)) {
                if (count($candidate) !== count(array_unique($candidate))) {
                    return [[], null, [['reason' => 'contact_headers_ambiguous', 'sheet' => $sheet->getTitle(), 'row' => $row]]];
                }
                $columns = $candidate;
                $header = $row;

                break;
            }
        }
        if (! $header) {
            return [[], null, [['reason' => 'contact_headers_missing', 'sheet' => $sheet->getTitle()]]];
        }
        $entries = [];
        for ($row = $header + 1; $row <= $last; $row++) {
            $values = [];
            $cells = [];
            foreach ($columns as $column => $field) {
                $values[$field] = $this->value($sheet, $column.$row);
                $cells[$field] = $column.$row;
            }
            $subject = $values['name'];
            if ($subject === '' || in_array(self::normalize($subject), ['name', 'mitarbeiter', 'mitarbeitername'], true)) {
                continue;
            }
            $entries[] = $this->entry('contacts', $sheet->getTitle(), (string) $row, $values, ['row' => $row, 'cells' => $cells, 'columns' => $columns, 'subject' => $subject, 'kind' => 'employee']);
        }

        return [$entries, ['kind' => 'employee', 'columns' => $columns, 'header' => $header, 'last_row' => $last], []];
    }

    private function coverage(WorkbookPackage $package, array $rows, array $sheets, array $issues): array
    {
        $domains = ['planning' => 0, 'contacts' => 0, 'competencies' => 0];
        $withEmployee = 0;
        foreach ($rows as $entry) {
            $domains[$entry['domain']]++;
            if ($entry['domain'] === 'planning' && trim((string) ($entry['values']['employee'] ?? '')) !== '') {
                $withEmployee++;
            }
        }
        $availability = array_filter($sheets, fn ($info) => ($info['kind'] ?? null) === 'availability_note');
        $people = array_sum(array_column($availability, 'people'));
        $notes = array_sum(array_column($availability, 'nonempty_notes'));

        return [
            'sheets_total' => count($package->sheets()),
            'sheets_recognized' => count($sheets),
            'ignored_sheets' => array_values(array_map(fn ($name) => ['sheet' => $name, 'reason' => 'unsupported_layout'], array_diff(array_keys($package->sheets()), array_keys($sheets)))),
            'by_domain' => $domains,
            'planning' => ['rows' => $domains['planning'], 'with_employee' => $withEmployee, 'without_employee' => $domains['planning'] - $withEmployee],
            'availability' => ['people' => $people, 'nonempty_notes' => $notes, 'blank_note_cells' => 2 * $people - $notes],
            'skipped_rows' => count(array_filter($issues, fn ($issue) => isset($issue['row']) && ! ($issue['retained'] ?? false))),
        ];
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
