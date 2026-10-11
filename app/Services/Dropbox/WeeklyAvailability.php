<?php

namespace App\Services\Dropbox;

use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Weekly workbook notes retain their source context; they are not approved absence windows. */
class WeeklyAvailability
{
    public function contextualize(array $parsed, DropboxSource $source): array
    {
        $availability = array_filter($parsed['rows'], fn ($entry) => in_array($entry['locator']['kind'] ?? '', ['availability_note', 'availability_submission'], true));
        if (! $availability) {
            return $parsed;
        }
        $week = $this->sourceWeek($parsed, $source);
        foreach ($parsed['sheets'] as &$sheet) {
            if (($sheet['kind'] ?? '') !== 'availability_note') {
                continue;
            }
            $sheet['week'] = $week;
            foreach ($sheet['columns'] as &$column) {
                $column['scope'] = $week ? $this->scope($week, $column['name']) : $column['scope'];
            }
            unset($column);
        }
        unset($sheet);
        if (! $week) {
            foreach (array_unique(array_column($availability, 'sheet')) as $sheet) {
                $parsed['issues'][] = ['reason' => 'availability_week_missing', 'sheet' => $sheet];
            }
            $parsed['rows'] = array_values(array_filter($parsed['rows'], fn ($entry) => ! in_array($entry['locator']['kind'] ?? '', ['availability_note', 'availability_submission'], true)));

            return $parsed;
        }
        foreach ($parsed['rows'] as &$entry) {
            if (in_array($entry['locator']['kind'] ?? '', ['availability_note', 'availability_submission'], true)) {
                $entry['locator']['week'] = $week;
                $entry['locator']['scope'] = $this->scope($week, $entry['locator']['name']);
            }
        }
        unset($entry);

        return $parsed;
    }

    public function sourceWeek(array $parsed, DropboxSource $source): ?string
    {
        $matcher = app(WeekFileMatcher::class);
        $rule = DropboxConnection::find($source->connection_id)?->option('filename_rule') ?: 'Aufträge KW {KW} [aktuell].xlsx';
        $name = $matcher->match($source->name, $rule);
        if ($name && $name['year']) {
            $week = CarbonImmutable::now('Europe/Berlin')->setISODate($name['year'], $name['week'], 1)->format('o-\WW');

            return $week === sprintf('%04d-W%02d', $name['year'], $name['week']) ? $week : null;
        }
        $weeks = array_values(array_unique(($parsed['weeks'] ?? []) ?: ($source->weeks ?? [])));
        if ($name) {
            $weeks = array_values(array_filter($weeks, fn ($week) => (int) substr($week, 6) === $name['week']));
        }

        return count($weeks) === 1 ? $weeks[0] : null;
    }

    public function scope(string $week, string $name): string
    {
        return $week.' | '.$name;
    }

    public function recordWeek(DropboxRecord $record): ?string
    {
        if (! in_array($record->metadata['kind'] ?? '', ['availability_note', 'availability_submission'], true)) {
            return null;
        }
        if (preg_match('/^\d{4}-W(?:0[1-9]|[1-4][0-9]|5[0-3])$/D', $record->metadata['week'] ?? '')) {
            return $record->metadata['week'];
        }
        $scope = $record->metadata['scope'] ?? EmployeeCompetencyFact::find($record->model_id)?->scope;

        return preg_match('/^(\d{4}-W(?:0[1-9]|[1-4][0-9]|5[0-3])) \| /D', $scope ?? '', $matches) ? $matches[1] : null;
    }

    /** Rebind the former global note without spreading an unattributed App edit over all weeks. */
    public function rebind(DropboxConnection $connection, DropboxSource $source, array $entries, bool $preview): void
    {
        if ($preview) {
            return;
        }
        $notes = collect($entries)->filter(fn ($entry) => ($entry['locator']['kind'] ?? '') === 'availability_note');
        if ($notes->isEmpty()) {
            return;
        }
        foreach ($notes->pluck('locator.subject')->unique() as $subject) {
            app(DomainAdapter::class)->identity($connection, $subject);
        }
        foreach (DropboxAppearance::where('source_id', $source->id)->get() as $appearance) {
            if (($appearance->locator['kind'] ?? '') !== 'availability_note' || isset($appearance->locator['week'])) {
                continue;
            }
            $matches = $notes->filter(fn ($entry) => WorkbookReader::normalize($entry['locator']['subject']) === WorkbookReader::normalize($appearance->locator['subject'] ?? '')
                && $entry['locator']['name'] === ($appearance->locator['name'] ?? null));
            if ($matches->count() !== 1) {
                continue;
            }
            $entry = $matches->first();
            DB::transaction(function () use ($connection, $source, $appearance, $entry) {
                $appearance = DropboxAppearance::lockForUpdate()->findOrFail($appearance->id);
                $legacyRecord = DropboxRecord::lockForUpdate()->findOrFail($appearance->record_id);
                $legacy = EmployeeCompetencyFact::find($legacyRecord->model_id);
                if (! $legacy || $legacy->kind !== 'availability_note' || $this->recordWeek($legacyRecord)) {
                    return;
                }
                $shared = ($legacyRecord->metadata['availability_shared_legacy'] ?? false)
                    || DropboxAppearance::where('record_id', $legacyRecord->id)->where('source_id', '!=', $source->id)->exists();
                if ($shared && ! ($legacyRecord->metadata['availability_shared_legacy'] ?? false)) {
                    $legacyRecord->update(['metadata' => [...($legacyRecord->metadata ?? []), 'availability_shared_legacy' => true]]);
                }
                $baseline = $appearance->baseline ?: $appearance->last_excel;
                $values = $shared ? $baseline : $legacy->value;
                $fact = EmployeeCompetencyFact::where('identity_id', $legacy->identity_id)->where('kind', 'availability_note')
                    ->where('name', $entry['locator']['name'])->where('scope', $entry['locator']['scope'])->first();
                if (! $fact) {
                    $fact = SyncContext::import(fn () => EmployeeCompetencyFact::create([
                        'identity_id' => $legacy->identity_id, 'kind' => 'availability_note', 'name' => $entry['locator']['name'],
                        'scope' => $entry['locator']['scope'], 'value' => $values,
                    ]));
                }
                $record = DropboxRecord::firstOrCreate([
                    'connection_id' => $connection->id, 'domain' => 'competencies', 'model_type' => 'EmployeeCompetencyFact', 'model_id' => $fact->id,
                ], ['metadata' => $entry['locator']]);
                $appearance->update(['record_id' => $record->id, 'locator' => $entry['locator']]);
                if ($shared && $legacy->value !== $baseline) {
                    app(ConflictStore::class)->put($connection, $source, $record, 'availability_legacy_change', [
                        'week' => $entry['locator']['week'], 'legacy_record_id' => $legacyRecord->id,
                        'app' => $legacy->value, 'baseline' => $baseline,
                        'messages' => ['sync' => ['Eine Änderung der bisherigen gemeinsamen Verfügbarkeitsangabe kann keiner Woche eindeutig zugeordnet werden.']],
                    ]);
                }
                if (! DropboxAppearance::where('record_id', $legacyRecord->id)->exists()) {
                    $legacyRecord->update(['metadata' => [...($legacyRecord->metadata ?? []), 'availability_legacy' => true]]);
                }
            });
        }
    }

    /** Retain obsolete region headings as evidence, never as employee notes or writable cells. */
    public function ignoreGroups(DropboxSource $source, array $parsed, bool $preview): void
    {
        if ($preview) {
            return;
        }
        foreach ($parsed['sheets'] as $sheet => $info) {
            foreach ($info['ignored_groups'] ?? [] as $row) {
                foreach (DropboxAppearance::where('source_id', $source->id)->where('sheet', $sheet)->get() as $appearance) {
                    if ((int) ($appearance->locator['row'] ?? 0) === (int) $row && ($appearance->locator['kind'] ?? '') === 'availability_note') {
                        $appearance->update(['locator' => [...$appearance->locator, 'ignored_group' => true]]);
                    }
                }
                DropboxConflict::where('source_id', $source->id)->whereIn('reason', ['mapping_required', 'ambiguous_row', 'row_missing'])
                    ->where('snapshot->sheet', $sheet)->where(function ($query) use ($row) {
                        $query->where('snapshot->slot', 'C'.$row)->orWhere('snapshot->slot', 'D'.$row)
                            ->orWhere('snapshot->previous_slot', 'C'.$row)->orWhere('snapshot->previous_slot', 'D'.$row);
                    })->update(['state' => 'resolved']);
            }
        }
    }
}
