<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FileSynchronizer
{
    public function __construct(private DropboxClient $client, private DomainAdapter $domain, private SyncGuard $guard, private ConflictStore $conflicts, private WorkLedger $ledger, private ?string $lockStore = null) {}

    public function sync(DropboxConnection $connection, DropboxSource $source, bool $preview = false, ?array $prepared = null): array
    {
        $lock = Cache::store($this->lockStore ?? config('dropbox.lock_store'))->lock('dropbox-file:'.$connection->id.':'.$source->file_id, 330);
        if (! $lock->get()) {
            throw new DropboxApiException('file_busy', 5);
        }
        try {
            return $this->process($connection, $source, $preview, $prepared);
        } finally {
            $lock->release();
        }
    }

    private function process(DropboxConnection $connection, DropboxSource $source, bool $preview, ?array $prepared): array
    {
        $fresh = $this->guard->current($connection, preview: $preview);
        $preview = $preview || $fresh->mode === DropboxMode::Preview;
        if (! $this->guard->pathAllowed($connection, $source->path) || in_array($source->state, ['excluded', 'missing'], true)) {
            throw new DropboxApiException('source_unavailable');
        }
        $download = $this->client->download($connection, $source->file_id);
        $rev = $download['metadata']['rev'];
        if (! $preview && $fresh->mode !== DropboxMode::Preview) {
            app(UploadRecovery::class)->recover($connection, $source, $download['metadata']);
        }
        $source->rev = $rev;
        $parsed = $connection->isLocalImport() && $prepared !== null ? $prepared : app(WorkbookReader::class)->read($download['bytes'], $source->profile);
        $summary = ['rows' => count($parsed['rows']), 'imported' => 0, 'exported' => 0, 'conflicts' => 0, 'new' => 0, 'preview' => $preview];
        $issueKeys = array_map(fn ($i) => ($i['reason'] ?? '').':'.($i['sheet'] ?? '').':'.($i['row'] ?? ''), $parsed['issues']);
        DropboxConflict::where('source_id', $source->id)->whereIn('reason', ['invalid_date', 'planning_headers_missing'])->get()
            ->filter(fn ($c) => ! in_array($c->reason.':'.($c->snapshot['sheet'] ?? '').':'.($c->snapshot['row'] ?? ''), $issueKeys, true))->each(fn ($c) => $c->update(['state' => 'resolved']));
        foreach ($parsed['issues'] as $issue) {
            $this->conflicts->put($connection, $source, null, $issue['reason'], $issue, ($issue['sheet'] ?? '').':'.($issue['row'] ?? ''));
            $summary['conflicts']++;
        }
        $entries = array_values(array_filter($parsed['rows'], fn ($r) => in_array($r['domain'], $connection->option('domains'), true)));
        if (! in_array('assignments', $connection->option('domains'), true)) {
            foreach ($entries as &$entry) {
                if ($entry['domain'] === 'planning') {
                    unset($entry['values']['employee'], $entry['locator']['cells']['employee']);
                    $entry['fingerprint'] = WorkbookReader::fingerprint($entry['values']);
                }
            } unset($entry);
        }
        $offset = ! $preview && (($source->progress['rev'] ?? '') === $rev || ($source->own_rev === $rev && $source->progress)) ? (int) ($source->progress['next'] ?? 0) : 0;
        $end = $preview ? count($entries) : min(count($entries), $offset + 200);
        $selected = [];
        for ($i = $offset; $i < $end; $i++) {
            $selected[$entries[$i]['sheet'].'!'.$entries[$i]['slot']] = true;
        }
        // Match content before positions. Row numbers are only output addresses.
        $existing = DropboxAppearance::where('source_id', $source->id)->get();
        $used = [];
        $bound = [];
        $unbound = [];
        $exact = $existing->groupBy(fn ($a) => $this->exactKey($a->sheet, $a->locator, $a->fingerprint));
        $bySubject = $existing->groupBy(fn ($a) => $this->subjectKey($a->sheet, $a->locator));
        $planningIndex = [];
        foreach ($existing as $appearance) {
            if (isset($appearance->locator['subject'])) {
                continue;
            }
            foreach (['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'] as $key) {
                if (! empty($appearance->last_excel[$key])) {
                    $planningIndex[json_encode([$appearance->sheet, $key, $appearance->last_excel[$key]], JSON_THROW_ON_ERROR)][$appearance->id] = $appearance;
                }
            }
        }
        $reserved = [];
        foreach ($entries as $entry) {
            $unchanged = $exact->get($this->exactKey($entry['sheet'], $entry['locator'], $entry['fingerprint']), collect());
            if ($unchanged->count() === 1) {
                $reserved[$unchanged->first()->id] = $entry['sheet'].'!'.$entry['slot'];
            }
        }
        $mappingDecisions = DropboxConflict::where('source_id', $source->id)->where('state', 'open')->whereIn('reason', ['mapping_required', 'ambiguous_row'])->whereNotNull('decision')->get()->groupBy(fn ($c) => ($c->snapshot['sheet'] ?? '').'!'.($c->snapshot['fingerprint'] ?? ''));
        foreach ($entries as $entry) {
            $decisions = $mappingDecisions->get($entry['sheet'].'!'.$entry['fingerprint']);
            $manual = ! $preview && $decisions ? DB::transaction(function () use ($connection, $source, $entry, $decisions) {
                $this->guard->current($connection, lock: true);

                return app(RowMapping::class)->apply($connection, $source, $entry, $decisions);
            }) : null;
            if ($manual && ! isset($used[$manual->id])) {
                $used[$manual->id] = true;
                $bound[] = ['entry' => $entry, 'appearance' => $manual];

                continue;
            }
            $matches = $exact->get($this->exactKey($entry['sheet'], $entry['locator'], $entry['fingerprint']), collect())->filter(fn ($a) => ! isset($used[$a->id]));
            if ($matches->count() !== 1) {
                $candidates = $entry['domain'] === 'planning'
                    ? $this->planningCandidates($entry, $planningIndex)
                    : $bySubject->get($this->subjectKey($entry['sheet'], $entry['locator']), collect());
                $matches = $candidates->filter(fn ($a) => ! isset($used[$a->id]) && (! isset($reserved[$a->id]) || $reserved[$a->id] === $entry['sheet'].'!'.$entry['slot']) && $this->sameIdentity($a, $entry));
                if ($matches->count() > 1 && $entry['domain'] === 'planning') {
                    $scores = $matches->mapWithKeys(fn ($a) => [$a->id => count(array_intersect_assoc(array_intersect_key($a->last_excel, array_flip(['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'])), $entry['values']))]);
                    $best = $scores->max();
                    $matches = $matches->filter(fn ($a) => $scores[$a->id] === $best);
                }
            }
            if ($matches->count() === 1) {
                $a = $matches->first();
                $used[$a->id] = true;
                $bound[] = ['entry' => $entry, 'appearance' => $a];
            } elseif ($matches->count() > 1) {
                $this->conflicts->put($connection, $source, null, 'ambiguous_row', app(RowMapping::class)->snapshot($connection, $entry), $entry['sheet'].'!'.$entry['slot']);
                $summary['conflicts']++;
            } else {
                $unbound[] = $entry;
            }
        }
        if (! $preview) {
            DB::transaction(function () use ($connection, $bound) {
                $this->guard->current($connection, lock: true);
                $moving = array_filter($bound, fn ($b) => $b['appearance']->slot !== $b['entry']['slot']);
                foreach ($moving as $b) {
                    DropboxAppearance::whereKey($b['appearance']->id)->update(['slot' => '@'.$b['appearance']->id]);
                }
                foreach ($moving as $b) {
                    $collision = DropboxAppearance::where('source_id', $b['appearance']->source_id)->where('sheet', $b['entry']['sheet'])->where('slot', $b['entry']['slot'])->first();
                    if ($collision) {
                        $collision->update(['slot' => 'missing:'.$collision->id]);
                    }
                    $b['appearance']->forceFill(['sheet' => $b['entry']['sheet'], 'slot' => $b['entry']['slot'], 'locator' => $b['entry']['locator']])->save();
                }
            });
        }
        usort($unbound, fn ($a, $b) => (int) ($b['locator']['master'] ?? false) <=> (int) ($a['locator']['master'] ?? false));
        foreach ($unbound as $entry) {
            if (! isset($selected[$entry['sheet'].'!'.$entry['slot']])) {
                continue;
            }
            $summary['new']++;
            $subject = $entry['locator']['subject'] ?? $entry['values']['employee'] ?? '';
            if ($subject !== '') {
                $this->domain->identity($connection, $subject, ($entry['locator']['kind'] ?? '') === 'provider' ? 'provider' : 'unresolved');
            }
            if ($preview) {
                continue;
            }
            try {
                $b = DB::transaction(function () use ($connection, $source, $entry, $rev) {
                    $this->guard->current($connection, lock: true);
                    $occupied = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('slot', $entry['slot'])->exists();
                    if ($occupied) {
                        throw ValidationException::withMessages(['sync' => 'Zeile enthält andere Einsatzdaten als bisher. Vor einer Neuanlage die Zuordnung prüfen.']);
                    }
                    $related = $this->related($connection, $source, $entry);
                    if ($related) {
                        $values = $this->domain->current($related);
                        $different = array_filter($entry['values'], fn ($v, $k) => ! in_array($k, ['draft'], true) && ($values[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
                        if ($different) {
                            throw ValidationException::withMessages(['sync' => 'Neue Kopie weicht vom bestehenden App-Datensatz ab. Zuordnung und Werte prüfen.']);
                        }
                        $record = $related;
                    } else {
                        $record = $this->domain->create($connection, $entry);
                    }
                    $collision = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('slot', $entry['slot'])->first();
                    if ($collision) {
                        $collision->update(['slot' => 'missing:'.$collision->id]);
                    }
                    $appearance = DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'locator' => $entry['locator'], 'fingerprint' => $entry['fingerprint'], 'baseline' => $entry['values'], 'last_excel' => $entry['values'], 'seen_rev' => $rev]);

                    return ['entry' => $entry, 'appearance' => $appearance];
                }, 3);
                $bound[] = $b;
                $summary['imported']++;
                app(RowMapping::class)->resolved($source, $entry);
            } catch (ValidationException $e) {
                $this->conflicts->put($connection, $source, null, 'mapping_required', [...app(RowMapping::class)->snapshot($connection, $entry), 'messages' => $e->errors()], $entry['sheet'].'!'.$entry['slot']);
                $summary['conflicts']++;
            }
        }
        $changes = [];
        foreach (collect($bound)->groupBy(fn ($b) => $b['appearance']->record_id) as $recordId => $rows) {
            if (! $rows->contains(fn ($b) => isset($selected[$b['entry']['sheet'].'!'.$b['entry']['slot']]))) {
                continue;
            }
            try {
                $result = DB::transaction(function () use ($connection, $source, $rev, $recordId, $rows, $preview) {
                    $this->guard->current($connection, preview: $preview, lock: true);
                    $record = DropboxRecord::lockForUpdate()->findOrFail($recordId);
                    $local = $this->domain->current($record);
                    $versions = $rows->map(fn ($b) => ['baseline' => $b['appearance']->baseline, 'excel' => $b['entry']['values'], 'origin' => $b['entry']['sheet'].'!'.$b['entry']['slot']])->all();
                    $merge = app(ThreeWayMerge::class)->merge($local, $versions);
                    $snapshot = ['generation' => $connection->generation, 'rev' => $rev, 'app' => $local, 'appearances' => $versions, 'fields' => $merge['conflicts']];
                    $decisionConflict = null;
                    if ($merge['conflicts']) {
                        $conflict = $this->conflicts->put($connection, $source, $record, 'field_conflict', $snapshot);
                        if ($conflict->decision && ! $preview) {
                            $merge['values'] = array_replace($merge['values'], $conflict->decision);
                            $decisionConflict = $conflict;
                        } else {
                            return ['conflict' => true, 'changes' => [], 'imported' => false];
                        }
                    }
                    $incoming = $merge['values'];
                    if (! in_array('assignments', $connection->option('domains'), true)) {
                        $incoming['employee'] = $local['employee'] ?? '';
                    }
                    // An Excel flag can never publish a schedule.
                    if ($record->domain === 'planning') {
                        $incoming['draft'] = $local['draft'];
                    }
                    $changed = $incoming !== $local;
                    if ($changed && ! $preview) {
                        $this->domain->apply($connection, $record, $incoming);
                    }
                    $target = $preview ? $incoming : $this->domain->current($record);
                    $retired = [];
                    if (! $preview && $record->domain === 'planning' && ($local['employee'] ?? '') !== ($target['employee'] ?? '')) {
                        $retired = $this->retirePersonalCopies($connection, $record, $local);
                    }
                    $out = [];
                    foreach ($rows as $b) {
                        $entry = $b['entry'];
                        $appearance = $b['appearance'];
                        $rowTarget = $retired[$appearance->id]['values'] ?? $target;
                        if (isset($retired[$appearance->id])) {
                            $appearance->record_id = $retired[$appearance->id]['record_id'];
                        }
                        $represented = array_intersect_key($rowTarget, $entry['values']);
                        if (WorkbookReader::fingerprint($represented) !== WorkbookReader::fingerprint($entry['values'])) {
                            $out[] = ['entry' => $entry, 'values' => $represented, 'appearance_id' => $appearance->id];
                        }
                        if (! $preview) {
                            $baseline = $appearance->baseline;
                            foreach ($entry['values'] as $field => $value) {
                                if (($rowTarget[$field] ?? null) === $value) {
                                    $baseline[$field] = $value;
                                }
                            }
                            $appearance->forceFill(['baseline' => $baseline, 'last_excel' => $entry['values'], 'fingerprint' => $entry['fingerprint'], 'seen_rev' => $rev])->save();
                        }
                    }
                    if (! $preview && $record->domain === 'planning') {
                        // Read raw source evidence after saving appearances. The merged
                        // draft flag above deliberately reflects publication, not Excel status.
                        app(ImportedOrderStatus::class)->reconcile($record, User::findOrFail(1));
                    }
                    if ($changed && ! $preview && ! $connection->isLocalImport()) {
                        $this->ledger->enqueue($connection, 'export', $record->model_type.':'.$record->model_id, ['type' => $record->model_type, 'id' => $record->model_id]);
                        foreach (DropboxAppearance::where('record_id', $record->id)->where('source_id', '!=', $source->id)->pluck('source_id')->unique() as $other) {
                            $this->ledger->enqueue($connection, 'file', 'file:'.$other, ['source_id' => $other]);
                        }
                    }
                    if ($decisionConflict) {
                        $decisionConflict->update(['state' => 'resolved']);
                    }
                    if (! $preview) {
                        DropboxConflict::where('source_id', $source->id)->where('record_id', $record->id)->whereIn('reason', ['business_rule', 'row_missing', 'field_conflict'])->update(['state' => 'resolved']);
                        foreach ($rows as $b) {
                            app(RowMapping::class)->resolved($source, $b['entry']);
                        }
                    }

                    return ['conflict' => false, 'changes' => $out, 'imported' => $changed];
                }, 3);
                if ($result['conflict']) {
                    $summary['conflicts']++;
                }
                if ($result['imported']) {
                    $summary['imported']++;
                }
                array_push($changes, ...$result['changes']);
            } catch (ValidationException $e) {
                $record = DropboxRecord::find($recordId);
                $this->conflicts->put($connection, $source, $record, 'business_rule', ['rev' => $rev, 'messages' => $e->errors()]);
                $summary['conflicts']++;
            }
        }
        foreach ($existing as $appearance) {
            if ($end < count($entries)) {
                break;
            }
            if (! isset($used[$appearance->id])) {
                $this->conflicts->put($connection, $source, DropboxRecord::find($appearance->record_id), 'row_missing', ['sheet' => $appearance->sheet, 'previous_slot' => $appearance->slot], (string) $appearance->id);
                $summary['conflicts']++;
            }
        }
        $summary['outgoing'] = count($changes);
        if ($changes && ! $preview && $connection->mode === DropboxMode::Bidirectional) {
            $bytes = app(WorkbookWriter::class)->write($download['bytes'], $changes, $source->profile);
            $metadata = app(RevisionUploader::class)->upload($connection, $source->path, $bytes, $rev, $changes);
            DB::transaction(function () use ($changes, $source, $metadata, &$rev, &$summary) {
                $rev = $metadata['rev'];
                foreach ($changes as $change) {
                    DropboxAppearance::findOrFail($change['appearance_id'])->forceFill(['baseline' => $change['values'], 'last_excel' => $change['values'], 'fingerprint' => WorkbookReader::fingerprint($change['values']), 'seen_rev' => $rev])->save();
                }
                $source->own_rev = $rev;
                $summary['exported'] = count($changes);
            });
        }
        $more = ! $preview && $end < count($entries);
        if (! $more) {
            $summary['conflicts'] = DropboxConflict::where('source_id', $source->id)->whereIn('state', ['open', 'rechecking'])->count();
        }
        $source->forceFill(['weeks' => $parsed['weeks'], 'rev' => $rev, 'state' => $more ? 'processing' : ($summary['conflicts'] ? 'conflicts' : ($preview ? 'preview' : ($summary['outgoing'] > $summary['exported'] ? 'pending_export' : 'synchronized')))]);
        if (! $preview) {
            $source->progress = $more ? ['next' => $end, 'rev' => $rev] : null;
            if (! $more) {
                $source->processed_rev = $rev;
            }
        }
        $source->save();
        if ($more && ! $connection->isLocalImport()) {
            $this->ledger->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
        }
        $times = [];
        if ($summary['imported'] && ! $preview) {
            $times['imported_at'] = now();
        } if ($summary['exported']) {
            $times['exported_at'] = now();
        }
        if ($times) {
            DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update($times);
        }
        DB::table('dropbox_runs')->insert(['connection_id' => $connection->id, 'kind' => $preview ? 'preview' : 'file', 'status' => $summary['conflicts'] ? 'attention' : 'ok', 'summary' => json_encode(['source_id' => $source->id, ...$summary]), 'created_at' => now(), 'updated_at' => now()]);

        return $summary;
    }

    private function exactKey(string $sheet, array $locator, string $fingerprint): string
    {
        // A color/value alone is not the identity of a competency or contact.
        return (array_key_exists('subject', $locator) ? $this->subjectKey($sheet, $locator) : $sheet).'!'.$fingerprint;
    }

    private function planningCandidates(array $entry, array $index): Collection
    {
        $counts = [];
        $candidates = [];
        foreach (['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'] as $key) {
            if (empty($entry['values'][$key])) {
                continue;
            }
            foreach ($index[json_encode([$entry['sheet'], $key, $entry['values'][$key]], JSON_THROW_ON_ERROR)] ?? [] as $id => $appearance) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                if ($counts[$id] >= 5) {
                    $candidates[$id] = $appearance;
                }
            }
        }

        return collect($candidates);
    }

    private function subjectKey(string $sheet, array $locator): string
    {
        // Match the existing non-planning identity rule without scanning every
        // cell in a sheet for each repeated color/value in a large matrix.
        return json_encode([$sheet, $locator['subject'] ?? null, $locator['kind'] ?? null, $locator['name'] ?? null], JSON_THROW_ON_ERROR);
    }

    private function sameIdentity(DropboxAppearance $appearance, array $entry): bool
    {
        if ($entry['domain'] !== 'planning') {
            $old = $appearance->locator;
            $new = $entry['locator'];

            return ($old['subject'] ?? null) === ($new['subject'] ?? null) && ($old['kind'] ?? null) === ($new['kind'] ?? null) && ($old['name'] ?? null) === ($new['name'] ?? null);
        }
        $old = $appearance->last_excel;
        $new = $entry['values'];
        // At least four semantic anchors, including time or reference; no row-index identity.
        $keys = ['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'];
        $matching = array_filter($keys, fn ($key) => ! empty($old[$key]) && ($old[$key] ?? null) === ($new[$key] ?? null));

        return count($matching) >= 5 && (in_array('starts', $matching, true) || in_array('train_reference', $matching, true));
    }

    /** Preserve the previous employee's copy as a visible cancellation; the exporter adds the new employee's sheet. */
    private function retirePersonalCopies(DropboxConnection $connection, DropboxRecord $record, array $previous): array
    {
        $copies = DropboxAppearance::where('record_id', $record->id)->get()->filter(fn ($a) => ! ($a->locator['master'] ?? false));
        if ($copies->isEmpty()) {
            return [];
        }
        $previous['cancelled'] = true;
        $previous['cancellation'] = trim('Storno '.$previous['cancellation']);
        $history = DropboxRecord::create(['connection_id' => $connection->id, 'domain' => 'planning', 'model_type' => 'ReportedAssignmentHistory', 'model_id' => $record->model_id, 'metadata' => ['historical_values' => $previous, 'employee_label' => $previous['employee']]]);
        $retired = [];
        foreach ($copies as $appearance) {
            $appearance->update(['record_id' => $history->id]);
            $retired[$appearance->id] = ['record_id' => $history->id, 'values' => $previous];
            if (! $connection->isLocalImport()) {
                $this->ledger->enqueue($connection, 'file', 'file:'.$appearance->source_id, ['source_id' => $appearance->source_id]);
            }
        }

        return $retired;
    }

    private function related(DropboxConnection $connection, DropboxSource $source, array $entry): ?DropboxRecord
    {
        if ($entry['domain'] !== 'planning') {
            $identity = $this->domain->identity($connection, $entry['locator']['subject']);
            if ($entry['domain'] === 'contacts') {
                return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'DropboxIdentity')->where('model_id', $identity->id)->first();
            }
            $fact = EmployeeCompetencyFact::where('identity_id', $identity->id)->where('kind', $entry['locator']['kind'])->where('name', $entry['locator']['name'])->where('scope', $entry['locator']['scope'])->first();

            return $fact ? DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'EmployeeCompetencyFact')->where('model_id', $fact->id)->first() : null;
        }
        $matches = DropboxAppearance::where('identity_hash', WorkbookReader::identityHash($entry['values']))->whereIn('record_id', DropboxRecord::where('connection_id', $connection->id)->where('domain', 'planning')->select('id'))->get()->filter(function ($a) use ($entry, $source) {
            if (($entry['locator']['master'] ?? false) && $a->source_id === $source->id && ($a->locator['master'] ?? false)) {
                return false;
            }
            foreach (['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'] as $key) {
                if (($a->last_excel[$key] ?? '') !== ($entry['values'][$key] ?? '')) {
                    return false;
                }
            }

            return true;
        })->pluck('record_id')->unique();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['sync' => 'Mehrere fachlich gleiche Einsätze; eine Kopie kann nicht eindeutig zugeordnet werden.']);
        }

        return $matches->count() === 1 ? DropboxRecord::find($matches->first()) : null;
    }
}
