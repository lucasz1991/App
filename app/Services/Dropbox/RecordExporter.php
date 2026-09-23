<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordExporter
{
    public function __construct(private DomainAdapter $domain, private DropboxClient $client, private WorkLedger $ledger, private SyncGuard $guard) {}

    public function export(DropboxConnection $connection, string $type, int $id, bool $preview = false): void
    {
        $this->guard->current($connection, preview: $preview);
        $preview = $preview || $connection->mode === DropboxMode::Preview;
        $records = DB::transaction(function () use ($connection, $type, $id, $preview) {
            $this->guard->current($connection, preview: $preview, lock: true);

            return $this->domain->recordsFor($connection, $type, $id, $preview);
        });
        if ($records->isEmpty()) {
            app(ConflictStore::class)->put($connection, null, null, 'not_representable', ['type' => $type, 'id' => $id], $type.':'.$id);

            return;
        }
        DropboxConflict::where('connection_id', $connection->id)->where('reason', 'not_representable')->get()
            ->filter(fn ($c) => ($c->snapshot['type'] ?? '') === $type && (int) ($c->snapshot['id'] ?? 0) === $id)
            ->each(fn ($c) => $c->update(['state' => 'resolved']));
        foreach ($records as $record) {
            if (! in_array($record->domain, $connection->option('domains'), true)) {
                continue;
            }
            $appearances = DropboxAppearance::where('record_id', $record->id)->get();
            foreach ($appearances->pluck('source_id')->unique() as $sourceId) {
                $this->ledger->enqueue($connection, 'file', ($preview ? 'preview:' : '').'file:'.$sourceId, ['source_id' => $sourceId, 'preview' => $preview]);
            }
            if ($preview || $connection->mode !== DropboxMode::Bidirectional) {
                $summary = ['record_id' => $record->id, 'preview' => $preview];
                if ($preview) {
                    $summary['preview_values'] = $type === 'EmployeeQualification'
                        ? (app(QualificationProjection::class)->projectedValue(EmployeeCompetencyFact::findOrFail($record->model_id)) ?? $this->domain->current($record))
                        : $this->domain->current($record);
                }
                DB::table('dropbox_runs')->insert(['connection_id' => $connection->id, 'kind' => 'export', 'status' => 'pending', 'summary' => json_encode($summary), 'created_at' => now(), 'updated_at' => now()]);

                continue;
            }
            if ($appearances->isNotEmpty()) {
                foreach (DropboxSource::whereIn('id', $appearances->pluck('source_id'))->where('profile', 'weekly')->get() as $source) {
                    $this->append($connection, $record, $source);
                }
            } else {
                try {
                    $this->append($connection, $record, $this->target($connection, $record));
                } catch (ValidationException $e) {
                    app(ConflictStore::class)->put($connection, null, $record, 'destination_required', ['messages' => $e->errors()]);
                }
            }
        }
    }

    private function target(DropboxConnection $connection, DropboxRecord $record): ?DropboxSource
    {
        if ($record->metadata['target_source_id'] ?? null) {
            return DropboxSource::where('connection_id', $connection->id)->whereKey($record->metadata['target_source_id'])->firstOrFail();
        }
        if ($record->domain === 'planning') {
            $week = app(WeekFileMatcher::class)->week($this->domain->current($record)['date']);
            $sources = DropboxSource::where('connection_id', $connection->id)->where('profile', 'weekly')->whereNotIn('state', ['missing', 'excluded'])->get()->filter(fn ($s) => in_array($week, $s->weeks ?? [], true));
            if ($sources->count() > 1) {
                throw ValidationException::withMessages(['sync' => 'Mehrere Zielmappen für '.$week.'. Ziel unter Konflikte auswählen.']);
            }
            if ($sources->count() === 1) {
                return $sources->first();
            }
            if (! $connection->option('create_workbooks')) {
                throw ValidationException::withMessages(['sync' => 'Wochenmappe fehlt und automatische Anlage ist ausgeschaltet.']);
            }

            return null;
        }
        $sources = DropboxSource::where('connection_id', $connection->id)->where('profile', 'matrix')->whereNotIn('state', ['missing', 'excluded'])->get();
        if ($sources->count() !== 1) {
            throw ValidationException::withMessages(['sync' => 'Eine eindeutige Kompetenzmatrix als Ziel fehlt.']);
        }

        return $sources->first();
    }

    private function append(DropboxConnection $connection, DropboxRecord $record, ?DropboxSource $source): void
    {
        $values = $this->domain->current($record);
        $week = $record->domain === 'planning' ? app(WeekFileMatcher::class)->week($values['date']) : null;
        $path = $source?->path ?? rtrim($connection->option('folders')[0], '/').'/'.app(WeekFileMatcher::class)->filename($week, $connection->option('filename_rule'));
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-file:'.$connection->id.':'.($source?->file_id ?? mb_strtolower($path)), 330);
        if (! $lock->get()) {
            throw new DropboxApiException('file_busy', 5);
        }
        try {
            $this->guard->current($connection, write: true);
            if (! $source) {
                try {
                    $metadata = $this->client->rpc($connection, 'files/get_metadata', ['path' => $path]);
                    $source = app(SourceScanner::class)->register($connection, $metadata);
                    if (! $source) {
                        throw new DropboxApiException('destination_excluded');
                    }
                    app(UploadRecovery::class)->recover($connection, $source, $metadata);
                    // Never append to an unknown pre-existing workbook before it has been compared.
                    if ($source->processed_rev !== $metadata['rev'] && $source->own_rev !== $metadata['rev']) {
                        throw new DropboxApiException('destination_needs_import', 10);
                    }
                } catch (DropboxApiException $e) {
                    if ($e->reason !== 'not_found') {
                        throw $e;
                    }
                }
            }
            if ($source) {
                if (! $this->guard->pathAllowed($connection, $source->path) || in_array($source->state, ['missing', 'excluded'], true)) {
                    throw new DropboxApiException('source_unavailable');
                }
                $download = $this->client->download($connection, $source->file_id);
                if ($source->processed_rev !== $download['metadata']['rev'] && $source->own_rev !== $download['metadata']['rev']) {
                    $this->ledger->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
                    throw new DropboxApiException('destination_needs_import', 10);
                }
                $bytes = $download['bytes'];
                $rev = $download['metadata']['rev'];
            } else {
                $bytes = app(TemplateService::class)->load($connection->option('week_template'));
                $rev = null;
            }
            $profile = $source?->profile ?? 'weekly';
            $parsed = app(WorkbookReader::class)->read($bytes, $profile);
            $appearances = $source ? DropboxAppearance::where('record_id', $record->id)->where('source_id', $source->id)->get() : collect();
            $changes = [];
            $extraCells = [];
            if ($record->domain === 'planning') {
                $master = collect($parsed['sheets'])->filter(fn ($s) => $s['master'] ?? false)->keys()->first();
                if (! $master) {
                    throw new DropboxApiException('master_sheet_missing');
                }
                $targets = [$master];
                if (! empty($values['employee']) && in_array('assignments', $connection->option('domains'), true)) {
                    $existingPersonal = $appearances->first(fn ($a) => ! ($a->locator['master'] ?? false));
                    if ($existingPersonal) {
                        $targets[] = $existingPersonal->sheet;
                    } else {
                        $sheet = preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $values['employee']);
                        $sheet = mb_substr(trim($sheet, " '\t\n\r"), 0, 31);
                        if ($sheet === '') {
                            throw new DropboxApiException('employee_sheet_name_missing');
                        }
                        if (! isset($parsed['sheets'][$sheet])) {
                            if (! $connection->option('create_sheets')) {
                                throw new DropboxApiException('employee_sheet_missing');
                            }
                            $employeeTemplate = app(TemplateService::class)->load($connection->option('employee_template'));
                            $package = new WorkbookPackage($bytes);
                            $headers = [];
                            foreach (WorkbookReader::HEADERS as $column => $label) {
                                $headers[$column.'1'] = ['value' => $label];
                            }
                            $package->addEmployeeSheet($sheet, $master, $headers, $employeeTemplate);
                            $bytes = $package->bytes();
                            $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');
                        }
                        $targets[] = $sheet;
                    }
                }
                foreach (array_unique($targets) as $sheet) {
                    if ($appearances->contains('sheet', $sheet)) {
                        continue;
                    }
                    if (! $connection->option('append_rows')) {
                        throw new DropboxApiException('new_rows_disabled');
                    }
                    $info = $parsed['sheets'][$sheet];
                    $row = max($info['header'] + 1, $info['last_row'] + 1);
                    $cells = [];
                    foreach ($info['columns'] as $column => $field) {
                        $cells[$field] = $column.$row;
                    }
                    $entry = ['domain' => 'planning', 'sheet' => $sheet, 'slot' => (string) $row, 'values' => $values, 'locator' => ['row' => $row, 'cells' => $cells, 'styles' => $info['styles'], 'columns' => $info['columns'], 'master' => $sheet === $master]];
                    $changes[] = ['entry' => $entry, 'values' => $values, 'record_id' => $record->id];
                }
            } else {
                if ($appearances->isNotEmpty()) {
                    return;
                }
                [$entry, $extraCells] = $this->matrixEntry($connection, $record, $parsed, $values);
                $changes[] = ['entry' => $entry, 'values' => $values, 'record_id' => $record->id];
            }
            if (! $changes) {
                return;
            }
            if ($extraCells) {
                $package = new WorkbookPackage($bytes);
                $package->patch($extraCells);
                $bytes = $package->bytes();
            }
            $bytes = app(WorkbookWriter::class)->write($bytes, $changes, $profile);
            $metadata = app(RevisionUploader::class)->upload($connection, $path, $bytes, $rev, $changes);
            DB::transaction(function () use ($connection, &$source, $metadata, $path, $profile, $week, $changes) {
                $source ??= new DropboxSource(['connection_id' => $connection->id, 'file_id' => $metadata['id'], 'first_seen_at' => now()]);
                $source->fill(['path' => $metadata['path_lower'] ?? $path, 'name' => $metadata['name'], 'profile' => $profile, 'rev' => $metadata['rev'], 'own_rev' => $metadata['rev'], 'processed_rev' => $metadata['rev'], 'state' => 'synchronized']);
                if ($week) {
                    $source->weeks = array_values(array_unique([...($source->weeks ?? []), $week]));
                }
                $source->save();
                foreach ($changes as $change) {
                    $entry = $change['entry'];
                    DropboxAppearance::updateOrCreate(['source_id' => $source->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot']], ['record_id' => $change['record_id'], 'locator' => $entry['locator'], 'baseline' => $change['values'], 'last_excel' => $change['values'], 'fingerprint' => WorkbookReader::fingerprint($change['values']), 'seen_rev' => $metadata['rev']]);
                }
                DropboxConnection::whereKey($connection->id)->update(['exported_at' => now()]);
            });
        } finally {
            $lock->release();
        }
    }

    private function matrixEntry(DropboxConnection $connection, DropboxRecord $record, array $parsed, array $values): array
    {
        $identity = $record->domain === 'contacts' ? DropboxIdentity::findOrFail($record->model_id) : DropboxIdentity::findOrFail(EmployeeCompetencyFact::findOrFail($record->model_id)->identity_id);
        $kind = $record->domain === 'contacts' ? ($identity->kind === 'provider' ? 'provider' : 'employee') : EmployeeCompetencyFact::findOrFail($record->model_id)->kind;
        $sheets = collect($parsed['sheets'])->filter(fn ($s) => ($s['kind'] ?? '') === $kind);
        if ($record->domain === 'competencies' && $kind !== 'local_knowledge') {
            $fact = EmployeeCompetencyFact::findOrFail($record->model_id);
            $sheets = $sheets->filter(fn ($s) => collect($s['columns'])->contains(fn ($c) => $c['name'] === $fact->name && $c['scope'] === $fact->scope));
        }
        if ($sheets->count() !== 1) {
            throw new DropboxApiException('matrix_target_ambiguous');
        }
        $sheet = $sheets->keys()->first();
        $info = $sheets->first();
        $row = max(3, $info['last_row'] + 1);
        $subject = $identity->details['display_name'] ?? $identity->alias;
        $extra = [];
        if ($record->domain === 'contacts') {
            $cells = [];
            foreach ($info['columns'] as $col => $field) {
                $cells[$field] = $col.$row;
            }
            $extra[$sheet]['A'.$row] = ['value' => $row - 2];

            return [['domain' => 'contacts', 'sheet' => $sheet, 'slot' => (string) $row, 'locator' => ['row' => $row, 'cells' => $cells, 'columns' => $info['columns'], 'kind' => $kind, 'subject' => $subject]], $extra];
        }
        $fact = EmployeeCompetencyFact::findOrFail($record->model_id);
        if ($kind === 'local_knowledge') {
            $cell = 'C'.$row;
            $extra[$sheet] = ['A'.$row => ['value' => $fact->name], 'B'.$row => ['value' => $subject]];
        } else {
            $other = collect($parsed['rows'])->first(fn ($e) => $e['sheet'] === $sheet && WorkbookReader::normalize($e['locator']['subject']) === $identity->alias);
            if ($other) {
                $row = $other['locator']['row'];
            } else {
                $details = $identity->details;
                if (empty($details['first_name']) || empty($details['last_name'])) {
                    throw new DropboxApiException('matrix_person_names_missing');
                }
                $extra[$sheet] = ['B'.$row => ['value' => $details['last_name']], 'C'.$row => ['value' => $details['first_name']]];
            }
            $col = collect($info['columns'])->filter(fn ($c) => $c['name'] === $fact->name && $c['scope'] === $fact->scope)->keys()->first();
            $cell = $col.$row;
        }

        return [['domain' => 'competencies', 'sheet' => $sheet, 'slot' => $cell, 'locator' => ['row' => $row, 'cell' => $cell, 'kind' => $kind, 'subject' => $subject, 'name' => $fact->name, 'scope' => $fact->scope]], $extra];
    }
}
