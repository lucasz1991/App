<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeAvailability;
use App\Models\User;
use App\Services\Operations\WorkforcePlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Typed informational wishes have their own sheet; free text never establishes calendar availability. */
class NativeAvailabilityWorkbook
{
    public const SHEET = 'App-Verfügbarkeiten';

    public const COLUMNS = ['B' => 'employee', 'C' => 'wish_id', 'D' => 'kind', 'E' => 'from', 'F' => 'until',
        'G' => 'weekdays', 'H' => 'whole_day', 'I' => 'start_time', 'J' => 'end_time', 'K' => 'timezone', 'L' => 'note', 'M' => 'status'];

    public const HEADERS = ['B' => 'Mitarbeiter', 'C' => 'Meldung-ID', 'D' => 'Art', 'E' => 'Von', 'F' => 'Bis',
        'G' => 'Wochentage', 'H' => 'Ganztägig', 'I' => 'Beginn', 'J' => 'Ende', 'K' => 'Zeitzone', 'L' => 'Notiz', 'M' => 'Status'];

    public function read(Worksheet $sheet): array
    {
        foreach (self::HEADERS as $column => $label) {
            if (WorkbookReader::normalize($this->value($sheet, $column.'1')) !== WorkbookReader::normalize($label)) {
                return [[], null, [['reason' => 'availability_headers_missing', 'sheet' => $sheet->getTitle(), 'row' => 1]]];
            }
        }
        $entries = [];
        $issues = [];
        $last = $sheet->getHighestDataRow();
        for ($row = 2; $row <= $last; $row++) {
            $values = [];
            $cells = [];
            foreach (self::COLUMNS as $column => $field) {
                $values[$field] = $this->value($sheet, $column.$row);
                $cells[$field] = $column.$row;
            }
            if (! array_filter($values, fn ($value) => $value !== '')) {
                continue;
            }
            $values['from'] = app(WorkbookReader::class)->date($values['from']);
            $values['until'] = app(WorkbookReader::class)->date($values['until']);
            if ($values['wish_id'] !== '' && ! ctype_digit((string) $values['wish_id'])) {
                $issues[] = ['reason' => 'availability_row_invalid', 'sheet' => $sheet->getTitle(), 'row' => $row];

                continue;
            }
            $values['wish_id'] = ctype_digit((string) $values['wish_id']) ? (int) $values['wish_id'] : 0;
            foreach (['start_time', 'end_time'] as $field) {
                if ($values[$field] !== '' && is_numeric($values[$field]) && (float) $values[$field] >= 0 && (float) $values[$field] < 1) {
                    $values[$field] = Date::excelToDateTimeObject((float) $values[$field])->format('H:i');
                }
            }
            $boolean = WorkbookReader::normalize($values['whole_day']);
            $values['whole_day'] = match ($boolean) {
                '1', 'ja', 'true', 'wahr' => true,
                '0', 'nein', 'false', 'falsch', '' => false,
                default => null,
            };
            $values['kind'] = match (WorkbookReader::normalize($values['kind'])) {
                'verfügbar' => 'available', 'bevorzugt' => 'preferred', 'nicht verfügbar' => 'unavailable', default => $values['kind'],
            };
            $values['status'] = match (WorkbookReader::normalize($values['status'])) {
                '', 'aktiv', 'active' => 'active', 'zurückgezogen', 'withdrawn' => 'withdrawn', default => $values['status'],
            };
            if (! $values['employee'] || ! $values['from'] || ! $values['until'] || $values['whole_day'] === null
                || ! $this->weeks($values['from'], $values['until'])) {
                $issues[] = ['reason' => 'availability_row_invalid', 'sheet' => $sheet->getTitle(), 'row' => $row];

                continue;
            }
            $week = app(WeekFileMatcher::class)->week($values['from']);
            $locator = ['row' => $row, 'cells' => $cells, 'kind' => 'availability_submission', 'subject' => $values['employee'],
                'name' => 'Verfügbarkeitsmeldung', 'scope' => app(WeeklyAvailability::class)->scope($week, 'Verfügbarkeitsmeldung'), 'week' => $week, 'wish_id' => $values['wish_id']];
            $entries[] = ['domain' => 'competencies', 'sheet' => $sheet->getTitle(), 'slot' => (string) $row,
                'values' => $values, 'locator' => $locator, 'fingerprint' => WorkbookReader::fingerprint($values)];
        }

        return [$entries, ['kind' => 'availability_submission', 'columns' => self::COLUMNS, 'header' => 1, 'last_row' => $last], $issues];
    }

    public function cells(array $entry, array $values): array
    {
        $cells = [];
        foreach ($entry['locator']['cells'] as $field => $address) {
            if (isset($entry['fingerprint']) && ($entry['values'][$field] ?? null) === ($values[$field] ?? null)) {
                continue;
            }
            $value = $values[$field] ?? '';
            if ($field === 'whole_day') {
                $value = $value ? 'ja' : 'nein';
            }
            $cells[$address] = ['value' => $value];
        }

        return $cells;
    }

    public function current(DropboxRecord $record): array
    {
        $wish = EmployeeAvailability::with('user')->find($record->model_id);
        if (! $wish) {
            if (isset($record->metadata['withdrawn_values'])) {
                return $record->metadata['withdrawn_values'];
            }
            throw ValidationException::withMessages(['sync' => 'Verfügbarkeitsmeldung fehlt; die Löschung muss geprüft werden.']);
        }
        $identity = DropboxIdentity::where('connection_id', $record->connection_id)->where('kind', 'employee')->where('user_id', $wish->user_id)->first();

        return ['employee' => trim($identity?->details['display_name'] ?? $wish->user->name), 'wish_id' => $wish->id, 'kind' => $wish->kind,
            'from' => $wish->from->toDateString(), 'until' => $wish->until->toDateString(), 'weekdays' => implode(',', $wish->weekdays),
            'whole_day' => $wish->whole_day, 'start_time' => $wish->start_time ?? '', 'end_time' => $wish->end_time ?? '',
            'timezone' => $wish->timezone, 'note' => trim($wish->note ?? ''),
            'status' => in_array($record->metadata['week'] ?? '', $this->weeks($wish->from->toDateString(), $wish->until->toDateString()), true) ? 'active' : 'withdrawn'];
    }

    public function related(DropboxConnection $connection, DropboxSource $source, array $entry): ?DropboxRecord
    {
        if ((int) $source->connection_id !== (int) $connection->id
            || app(WeeklyAvailability::class)->sourceWeek(['weeks' => $source->weeks ?? []], $source) !== ($entry['locator']['week'] ?? null)) {
            throw ValidationException::withMessages(['sync' => 'Verfügbarkeitsmeldung und Quellenwoche stimmen nicht überein.']);
        }
        $id = (int) ($entry['values']['wish_id'] ?? 0);
        if (! $id) {
            return null;
        }
        $record = DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'EmployeeAvailability')->where('model_id', $id)
            ->where('metadata->week', $entry['locator']['week'])->first();
        if (! $record) {
            throw ValidationException::withMessages(['sync' => 'Meldung-ID ist für diese Dropbox-Verbindung und Woche nicht freigegeben.']);
        }
        $identity = app(DomainAdapter::class)->identity($connection, $entry['locator']['subject']);
        $owner = EmployeeAvailability::find($record->model_id)?->user_id ?? ($record->metadata['user_id'] ?? null);
        if ($identity->kind !== 'employee' || ! $identity->user_id || (int) $identity->user_id !== (int) $owner) {
            throw ValidationException::withMessages(['sync' => 'Meldung-ID und Mitarbeiter stimmen nicht überein.']);
        }

        return $record;
    }

    public function create(DropboxConnection $connection, array $entry): DropboxRecord
    {
        if (! empty($entry['values']['wish_id'])) {
            throw ValidationException::withMessages(['sync' => 'Bestehende Meldung-ID muss zuerst über eine bekannte App-Kopie zugeordnet werden.']);
        }
        $record = new DropboxRecord(['connection_id' => $connection->id, 'domain' => 'competencies', 'model_type' => 'EmployeeAvailability',
            'model_id' => 0, 'metadata' => $entry['locator']]);
        $this->apply($connection, $record, $entry['values']);

        return $record;
    }

    public function apply(DropboxConnection $connection, DropboxRecord $record, array $values): void
    {
        if ((int) $record->connection_id !== (int) $connection->id || ($record->metadata['kind'] ?? '') !== 'availability_submission') {
            throw ValidationException::withMessages(['sync' => 'Verfügbarkeitsmeldung gehört nicht zu dieser Verbindung.']);
        }
        Validator::make($values, ['employee' => 'required|string|max:191', 'wish_id' => 'required|integer|min:0',
            'status' => 'required|in:active,withdrawn', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        if ($record->model_id && (int) $values['wish_id'] !== (int) $record->model_id) {
            throw ValidationException::withMessages(['sync' => 'Eine Meldung-ID darf nicht auf einen anderen Datensatz geändert werden.']);
        }
        $identity = app(DomainAdapter::class)->identity($connection, $values['employee']);
        $owner = $identity->kind === 'employee' && $identity->user_id ? User::find($identity->user_id) : null;
        if (! $owner || ! $owner->status || $owner->role !== 'staff') {
            throw ValidationException::withMessages(['sync' => 'Verfügbarkeit erfordert ein eindeutig zugeordnetes aktives Mitarbeiterkonto.']);
        }
        $wish = $record->model_id ? EmployeeAvailability::lockForUpdate()->find($record->model_id) : null;
        if ($record->model_id && ! $wish) {
            if ($values['status'] === 'withdrawn' && isset($record->metadata['withdrawn_values'])) {
                return;
            }
            throw ValidationException::withMessages(['sync' => 'Zurückgezogene Meldung kann nicht automatisch wiederhergestellt werden.']);
        }
        if ($wish && (int) $wish->user_id !== (int) $owner->id) {
            throw ValidationException::withMessages(['sync' => 'Mitarbeiter einer bestehenden Meldung darf nicht geändert werden.']);
        }
        $week = $record->metadata['week'];
        $weeks = $this->weeks($values['from'], $values['until']);
        if (! in_array($week, $weeks, true)) {
            throw ValidationException::withMessages(['sync' => 'Verfügbarkeitsmeldung liegt außerhalb ihrer Wochenmappe.']);
        }
        if ($values['status'] === 'withdrawn') {
            if (! $wish) {
                throw ValidationException::withMessages(['sync' => 'Eine neue Meldung muss aktiv sein.']);
            }
            app(WorkforcePlanningService::class)->removeWish($wish->id, $wish->revision, 'Über Excel zurückgezogen', $owner);
            app(WorkLedger::class)->enqueue($connection, 'export', 'EmployeeAvailability:'.$wish->id, ['type' => 'EmployeeAvailability', 'id' => $wish->id]);

            return;
        }
        $data = array_intersect_key($values, array_flip(['kind', 'from', 'until', 'whole_day', 'start_time', 'end_time', 'timezone', 'note']));
        $data['weekdays'] = array_map('intval', explode(',', (string) ($values['weekdays'] ?? '')));
        $data['availability_period_id'] = $wish?->availability_period_id;
        $data['preferred_pool_id'] = $wish?->preferred_pool_id;
        $data['late_reason'] = 'Über Excel synchronisiert';
        $wish = app(WorkforcePlanningService::class)->saveWish($wish?->id, $wish?->revision, $data, $owner);
        $record->forceFill(['model_id' => $wish->id, 'metadata' => [...$record->metadata, 'user_id' => $owner->id, 'wish_id' => $wish->id]])->save();
        app(WorkLedger::class)->enqueue($connection, 'export', 'EmployeeAvailability:'.$wish->id, ['type' => 'EmployeeAvailability', 'id' => $wish->id]);
    }

    public function recordsFor(DropboxConnection $connection, int $id, bool $preview): Collection
    {
        $wish = EmployeeAvailability::find($id);
        if ($wish) {
            foreach ($this->weeks($wish->from->toDateString(), $wish->until->toDateString()) as $week) {
                if (! DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'EmployeeAvailability')->where('model_id', $id)->where('metadata->week', $week)->exists()) {
                    DropboxRecord::create(['connection_id' => $connection->id, 'domain' => 'competencies', 'model_type' => 'EmployeeAvailability', 'model_id' => $id,
                        'metadata' => ['kind' => 'availability_submission', 'week' => $week,
                            'scope' => app(WeeklyAvailability::class)->scope($week, 'Verfügbarkeitsmeldung'), 'name' => 'Verfügbarkeitsmeldung', 'wish_id' => $id, 'user_id' => $wish->user_id]]);
                }
            }
        }

        return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'EmployeeAvailability')->where('model_id', $id)->get();
    }

    public function appendEntry(DropboxConnection $connection, DropboxRecord $record, array $parsed, array $values): array
    {
        $info = $parsed['sheets'][self::SHEET] ?? null;
        if (! $info) {
            throw new DropboxApiException('availability_sheet_missing');
        }
        $row = max(2, $info['last_row'] + 1);
        $cells = [];
        foreach (self::COLUMNS as $column => $field) {
            $cells[$field] = $column.$row;
        }
        $locator = ['row' => $row, 'cells' => $cells, 'kind' => 'availability_submission', 'subject' => $values['employee'],
            'name' => 'Verfügbarkeitsmeldung', 'scope' => $record->metadata['scope'], 'week' => $record->metadata['week'], 'wish_id' => $record->model_id];

        return [['domain' => 'competencies', 'sheet' => self::SHEET, 'slot' => (string) $row, 'values' => $values, 'locator' => $locator], []];
    }

    public function captureWithdrawal(EmployeeAvailability $wish): void
    {
        foreach (DropboxRecord::where('model_type', 'EmployeeAvailability')->where('model_id', $wish->id)->get() as $record) {
            $values = $this->current($record);
            $values['status'] = 'withdrawn';
            $record->update(['metadata' => [...$record->metadata, 'withdrawn_values' => $values, 'user_id' => $wish->user_id]]);
        }
    }

    public function weeks(string $from, string $until): array
    {
        if ($until < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) > 365) {
            return [];
        }
        $weeks = [];
        for ($day = CarbonImmutable::parse($from)->startOfWeek(); $day->toDateString() <= $until; $day = $day->addWeek()) {
            $weeks[] = $day->format('o-\WW');
        }

        return $weeks;
    }

    private function value(Worksheet $sheet, string $address): string
    {
        $cell = $sheet->getCell($address);
        $value = $cell->getDataType() === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();

        return trim((string) ($value ?? ''));
    }
}
