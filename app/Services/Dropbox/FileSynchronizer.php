<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsTransaction;
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
        $parsed = app(WeeklyAvailability::class)->contextualize($parsed, $source);
        app(WeeklyAvailability::class)->ignoreGroups($source, $parsed, $preview);
        $summary = ['rows' => count($parsed['rows']), 'imported' => 0, 'exported' => 0, 'conflicts' => 0, 'new' => 0, 'preview' => $preview];
        $issueKeys = array_map(fn ($i) => ($i['reason'] ?? '').':'.($i['sheet'] ?? '').':'.($i['row'] ?? ''), $parsed['issues']);
        DropboxConflict::where('source_id', $source->id)->whereIn('reason', ['invalid_date', 'planning_headers_missing', 'planning_date_missing', 'planning_time_missing', 'planning_columns_missing', 'contact_headers_missing', 'contact_headers_ambiguous', 'availability_headers_missing', 'availability_row_invalid', 'availability_week_missing'])->get()
            ->filter(fn ($c) => ! in_array($c->reason.':'.($c->snapshot['sheet'] ?? '').':'.($c->snapshot['row'] ?? ''), $issueKeys, true))->each(fn ($c) => $c->update(['state' => 'resolved']));
        foreach ($parsed['issues'] as $issue) {
            $this->conflicts->put($connection, $source, null, $issue['reason'], $issue, ($issue['sheet'] ?? '').':'.($issue['row'] ?? ''));
            $summary['conflicts']++;
        }
        $entries = array_values(array_filter($parsed['rows'], fn ($r) => in_array($r['domain'], $connection->option('domains'), true)));
        $parsed['coverage']['disabled_rows'] = count($parsed['rows']) - count($entries);
        app(WeeklyAvailability::class)->rebind($connection, $source, $entries, $preview);
        if (! in_array('assignments', $connection->option('domains'), true)) {
            foreach ($entries as &$entry) {
                if ($entry['domain'] === 'planning') {
                    unset($entry['values']['employee'], $entry['locator']['cells']['employee']);
                    $entry['fingerprint'] = WorkbookReader::fingerprint($entry['values']);
                }
            } unset($entry);
        }
        $offset = ! $preview && (($source->progress['rev'] ?? '') === $rev || ($source->own_rev === $rev && $source->progress)) ? (int) ($source->progress['next'] ?? 0) : 0;
        $chunkSize = max(50, min(600, (int) ($connection->option('import_chunk_size') ?: 200)));
        $end = $preview ? count($entries) : min(count($entries), $offset + $chunkSize);
        $selected = [];
        for ($i = $offset; $i < $end; $i++) {
            $selected[$entries[$i]['sheet'].'!'.$entries[$i]['slot']] = true;
        }
        // Match content before positions. Row numbers are only output addresses.
        $existing = DropboxAppearance::where('source_id', $source->id)->get()->reject(fn ($a) => $a->locator['ignored_group'] ?? false);
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
        $pending = collect($entries)->keyBy(fn ($entry) => $entry['sheet'].'!'.$entry['slot'])->all();
        $contentCounts = collect($entries)->countBy(fn ($entry) => $entry['sheet'].'!'.$entry['fingerprint']);
        $mappingDecisions = DropboxConflict::where('source_id', $source->id)->whereIn('state', ['open', 'rechecking'])->whereIn('reason', ['mapping_required', 'ambiguous_row'])->whereNotNull('decision')->get()->groupBy(fn ($c) => ($c->snapshot['sheet'] ?? '').'!'.($c->snapshot['fingerprint'] ?? ''));
        foreach ($pending as $key => $entry) {
            $decisions = $mappingDecisions->get($entry['sheet'].'!'.$entry['fingerprint']);
            $manual = ! $preview && $decisions && $contentCounts[$entry['sheet'].'!'.$entry['fingerprint']] === 1 ? DB::transaction(function () use ($connection, $source, $entry, $decisions) {
                $this->guard->current($connection, lock: true);

                return app(RowMapping::class)->apply($connection, $source, $entry, $decisions);
            }) : null;
            if ($manual && ! isset($used[$manual->id])) {
                $used[$manual->id] = true;
                $bound[] = ['entry' => $entry, 'appearance' => $manual];
                unset($pending[$key]);
            }
        }
        // Exact pairs are reserved together, before any approximate candidate can take one.
        $exactCounts = collect($pending)->countBy(fn ($entry) => $this->exactKey($entry['sheet'], $entry['locator'], $entry['fingerprint']));
        foreach ($pending as $key => $entry) {
            $exactKey = $this->exactKey($entry['sheet'], $entry['locator'], $entry['fingerprint']);
            $matches = $exact->get($this->exactKey($entry['sheet'], $entry['locator'], $entry['fingerprint']), collect())->filter(fn ($a) => ! isset($used[$a->id]));
            if ($matches->count() === 1 && $exactCounts[$exactKey] === 1) {
                $appearance = $matches->first();
                $used[$appearance->id] = true;
                $bound[] = ['entry' => $entry, 'appearance' => $appearance];
                unset($pending[$key]);
            }
        }
        $candidates = [];
        foreach ($pending as $key => $entry) {
            $pool = $entry['domain'] === 'planning' ? $this->planningCandidates($entry, $planningIndex)
                : $bySubject->get($this->subjectKey($entry['sheet'], $entry['locator']), collect());
            $candidates[$key] = $pool->filter(fn ($appearance) => ! isset($used[$appearance->id]) && $this->sameIdentity($appearance, $entry))
                ->mapWithKeys(fn ($appearance) => [$appearance->id => ['appearance' => $appearance, 'score' => $this->matchScore($appearance, $entry)]])->all();
        }
        // A pair is safe only when both the row and the existing appearance prefer each other uniquely.
        do {
            $reverse = [];
            foreach ($pending as $key => $entry) {
                foreach ($candidates[$key] ?? [] as $id => $candidate) {
                    if (! isset($used[$id])) {
                        $reverse[$id][$key] = $candidate['score'];
                    }
                }
            }
            $pairs = [];
            foreach ($pending as $key => $entry) {
                $available = array_filter($candidates[$key] ?? [], fn ($candidate) => ! isset($used[$candidate['appearance']->id]));
                if (! $available) {
                    continue;
                }
                $best = max(array_column($available, 'score'));
                $preferred = array_filter($available, fn ($candidate) => $candidate['score'] === $best);
                if (count($preferred) !== 1) {
                    continue;
                }
                $candidate = reset($preferred);
                $owners = $reverse[$candidate['appearance']->id];
                $owners = array_filter($owners, fn ($score) => $score === max($owners));
                if (count($owners) === 1 && isset($owners[$key])) {
                    $pairs[$key] = $candidate['appearance'];
                }
            }
            foreach ($pairs as $key => $appearance) {
                $used[$appearance->id] = true;
                $bound[] = ['entry' => $pending[$key], 'appearance' => $appearance];
                unset($pending[$key]);
            }
        } while ($pairs);
        // Repeated cells of one fact are copies of the same identity, not competing people.
        foreach ($pending as $key => $entry) {
            if ($entry['domain'] === 'planning') {
                continue;
            }
            $available = collect($candidates[$key] ?? [])->filter(fn ($candidate) => ! isset($used[$candidate['appearance']->id]));
            if ($available->isEmpty() || $available->pluck('appearance.record_id')->unique()->count() !== 1) {
                continue;
            }
            $appearance = $available->pluck('appearance')->first(fn ($candidate) => $candidate->slot === $entry['slot'])
                ?? $available->pluck('appearance')->sort(fn ($left, $right) => strnatcmp($left->slot, $right->slot))->first();
            $used[$appearance->id] = true;
            $bound[] = ['entry' => $entry, 'appearance' => $appearance];
            unset($pending[$key]);
        }
        foreach ($pending as $key => $entry) {
            $available = array_filter($candidates[$key] ?? [], fn ($candidate) => ! isset($used[$candidate['appearance']->id]));
            if ($available) {
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
                $b = OperationsTransaction::run(function () use ($connection, $source, $entry, $rev) {
                    $this->guard->current($connection, lock: true);
                    $occupied = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('slot', $entry['slot'])->exists();
                    if ($occupied) {
                        throw ValidationException::withMessages(['sync' => 'Zeile enthält andere Einsatzdaten als bisher. Vor einer Neuanlage die Zuordnung prüfen.']);
                    }
                    $related = $this->related($connection, $source, $entry);
                    $baseline = $entry['values'];
                    if ($related) {
                        $values = $this->domain->current($related);
                        $baseline = app(InitialCopyBaseline::class)->forCopy($values, $entry['values'], DropboxAppearance::where('record_id', $related->id)->get(), $entry['domain']);
                        $record = $related;
                    } else {
                        $record = $this->domain->create($connection, $entry);
                    }
                    $collision = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('slot', $entry['slot'])->first();
                    if ($collision) {
                        $collision->update(['slot' => 'missing:'.$collision->id]);
                    }
                    $appearance = DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'locator' => $entry['locator'], 'fingerprint' => $entry['fingerprint'], 'baseline' => $baseline, 'last_excel' => $entry['values'], 'seen_rev' => $rev]);

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
                $result = OperationsTransaction::run(function () use ($connection, $source, $rev, $recordId, $rows, $preview) {
                    $this->guard->current($connection, preview: $preview, lock: true);
                    $record = DropboxRecord::lockForUpdate()->findOrFail($recordId);
                    if ($record->domain === 'planning' && $record->model_type === 'Shift') {
                        // The service is shared by multiple records. Lock it before
                        // reading the merge basis, not only when applying that basis.
                        $shift = Shift::lockForUpdate()->findOrFail($record->model_id);
                        Order::lockForUpdate()->findOrFail($shift->order_id);
                    }
                    if (! $preview) {
                        app(HistoricalImportCompletion::class)->apply($record, User::findOrFail(1));
                        $this->domain->reconcileNative($connection, $record);
                    }
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
                    if (($changed || ($record->metadata['assignment_pending'] ?? false)) && ! $preview) {
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
                        if ($record->domain === 'planning' && ! isset($record->metadata['historical_values'])) {
                            $represented += array_intersect_key($rowTarget, array_flip(PlanningServiceProjection::FIELDS));
                        }
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
                        if ($record->domain === 'planning' && array_intersect_key($incoming, array_flip(PlanningServiceProjection::FIELDS)) !== array_intersect_key($local, array_flip(PlanningServiceProjection::FIELDS))) {
                            $orderId = Shift::find($record->model_id)?->order_id;
                            if ($orderId) {
                                $this->ledger->enqueue($connection, 'export', 'Order:'.$orderId, ['type' => 'Order', 'id' => $orderId]);
                            }
                        }
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
            if (($appearance->locator['kind'] ?? '') === 'availability_note'
                && ($parsed['sheets'][$appearance->sheet]['kind'] ?? '') === 'availability_note'
                && empty($parsed['sheets'][$appearance->sheet]['week'])) {
                continue; // An unclear year is a scope issue, not a deleted cell.
            }
            if (! isset($used[$appearance->id])) {
                $this->conflicts->put($connection, $source, DropboxRecord::find($appearance->record_id), 'row_missing', ['sheet' => $appearance->sheet, 'previous_slot' => $appearance->slot], (string) $appearance->id);
                $summary['conflicts']++;
            }
        }
        $summary['outgoing'] = count($changes);
        if ($changes && ! $preview && $connection->mode === DropboxMode::Bidirectional) {
            $written = app(WorkbookWriter::class)->writeChanges($download['bytes'], $changes, $source->profile);
            $bytes = $written['bytes'];
            $changes = $written['changes'];
            $metadata = app(RevisionUploader::class)->upload($connection, $source->path, $bytes, $rev, $changes);
            DB::transaction(function () use ($changes, $source, $metadata, &$rev, &$summary) {
                $rev = $metadata['rev'];
                foreach ($changes as $change) {
                    DropboxAppearance::findOrFail($change['appearance_id'])->forceFill(['locator' => $change['entry']['locator'], 'baseline' => $change['values'], 'last_excel' => $change['values'], 'fingerprint' => WorkbookReader::fingerprint($change['values']), 'seen_rev' => $rev])->save();
                }
                $source->own_rev = $rev;
                $summary['exported'] = count($changes);
            });
        }
        $more = ! $preview && $end < count($entries);
        $summary['file_outgoing'] = ($offset > 0 ? (int) ($source->progress['outgoing_total'] ?? 0) : 0) + $summary['outgoing'];
        $summary['file_exported'] = ($offset > 0 ? (int) ($source->progress['exported_total'] ?? 0) : 0) + $summary['exported'];
        if (! $more) {
            if (! $preview) {
                app(RowMapping::class)->reconcile($source, $entries, $parsed['issues'], $parsed['coverage']['ignored_sheets'] ?? []);
            }
            $summary['conflicts'] = DropboxConflict::where('source_id', $source->id)->whereIn('state', ['open', 'rechecking'])->count();
        }
        $source->forceFill(['weeks' => $parsed['weeks'], 'rev' => $rev, 'state' => $more ? 'processing' : ($summary['conflicts'] ? 'conflicts' : ($preview ? 'preview' : ($summary['file_outgoing'] > $summary['file_exported'] ? 'pending_export' : 'synchronized')))]);
        if (! $preview) {
            $source->progress = $more ? ['next' => $end, 'rev' => $rev, 'outgoing_total' => $summary['file_outgoing'], 'exported_total' => $summary['file_exported']] : null;
            if (! $more) {
                $source->processed_rev = $rev;
            }
        }
        $source->save();
        if (! $preview) {
            $source->forceFill(['last_report' => app(SourceSyncReport::class)->build($source, $parsed, $summary, $end, count($entries))])->save();
        }
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
        return json_encode([$sheet, $locator['subject'] ?? null, $locator['kind'] ?? null, $locator['name'] ?? null, $locator['scope'] ?? null, $locator['wish_id'] ?? null], JSON_THROW_ON_ERROR);
    }

    private function sameIdentity(DropboxAppearance $appearance, array $entry): bool
    {
        if ($entry['domain'] !== 'planning') {
            $old = $appearance->locator;
            $new = $entry['locator'];

            return ($old['subject'] ?? null) === ($new['subject'] ?? null) && ($old['kind'] ?? null) === ($new['kind'] ?? null) && ($old['name'] ?? null) === ($new['name'] ?? null) && ($old['scope'] ?? null) === ($new['scope'] ?? null) && ($old['wish_id'] ?? null) === ($new['wish_id'] ?? null);
        }
        $old = $appearance->last_excel;
        $new = $entry['values'];
        if (empty($old['date']) || ($old['date'] ?? null) !== ($new['date'] ?? null)) {
            return false; // A copied row in another week is a new occurrence, not a moved shift.
        }
        // At least four semantic anchors, including time or reference; no row-index identity.
        $keys = ['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'];
        $matching = array_filter($keys, fn ($key) => ! empty($old[$key]) && ($old[$key] ?? null) === ($new[$key] ?? null));

        return count($matching) >= 5 && (in_array('starts', $matching, true) || in_array('train_reference', $matching, true));
    }

    private function matchScore(DropboxAppearance $appearance, array $entry): int
    {
        if ($entry['domain'] !== 'planning') {
            return 1;
        }

        return count(array_filter(['date', 'starts', 'ends', 'employee', 'train_reference', 'location', 'customer', 'role'],
            fn ($key) => ! empty($appearance->last_excel[$key]) && ($appearance->last_excel[$key] ?? null) === ($entry['values'][$key] ?? null)));
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
        if (($entry['locator']['kind'] ?? '') === 'availability_submission') {
            return app(NativeAvailabilityWorkbook::class)->related($connection, $source, $entry);
        }
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
            $reference = trim((string) ($entry['values']['service_reference'] ?? ''));
            if ($reference !== '') {
                $record = DropboxRecord::find($a->record_id);
                $knownReference = $a->last_excel['service_reference'] ?? ($record ? ($this->domain->current($record)['service_reference'] ?? '') : '');
                if ($knownReference !== '' && $knownReference !== $reference) {
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
