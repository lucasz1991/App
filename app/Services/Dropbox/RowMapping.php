<?php

namespace App\Services\Dropbox;

use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;

class RowMapping
{
    public function snapshot(DropboxConnection $connection, array $entry): array
    {
        return ['generation' => $connection->generation, 'domain' => $entry['domain'], 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'fingerprint' => $entry['fingerprint'], 'values' => $entry['values']];
    }

    /** An explicit mapping is still bound to the reviewed business content, never just its row number. */
    public function apply(DropboxConnection $connection, DropboxSource $source, array $entry, iterable $conflicts): ?DropboxAppearance
    {
        $eligible = collect($conflicts)->filter(function ($conflict) use ($connection, $source, $entry) {
            $snapshot = $conflict->snapshot;

            return (int) $conflict->connection_id === (int) $connection->id && (int) $conflict->source_id === (int) $source->id
                && ($snapshot['generation'] ?? null) === $connection->generation && ($snapshot['domain'] ?? null) === $entry['domain']
                && ($snapshot['sheet'] ?? '') === $entry['sheet'] && ($snapshot['fingerprint'] ?? '') === $entry['fingerprint'];
        });
        if ($eligible->pluck('decision.record_id')->unique()->count() !== 1) {
            return null;
        }
        foreach ($eligible as $conflict) {
            $record = DropboxRecord::where('connection_id', $connection->id)->where('domain', $entry['domain'])->find($conflict->decision['record_id'] ?? null);
            if (! $record) {
                continue;
            }
            $appearances = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('record_id', $record->id)->get();
            if ($appearances->count() > 1) {
                return null;
            }
            $appearance = $appearances->first();
            if (! $appearance) {
                $collision = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('slot', $entry['slot'])->first();
                if ($collision) {
                    $collision->update(['slot' => 'missing:'.$collision->id]);
                }
                $appearance = DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'locator' => $entry['locator'], 'baseline' => [], 'last_excel' => [], 'fingerprint' => '']);
            }
            $conflict->update(['state' => 'resolved']);

            return $appearance;
        }

        return null;
    }

    public function resolved(DropboxSource $source, array $entry): void
    {
        DropboxConflict::where('source_id', $source->id)->whereIn('reason', ['mapping_required', 'ambiguous_row'])->whereIn('state', ['open', 'rechecking'])
            ->where('snapshot->sheet', $entry['sheet'])->where('snapshot->slot', (string) $entry['slot'])
            ->update(['state' => 'resolved']);
    }

    /** Only a completed revision can retire stale position-based hints; parser failures retain their evidence. */
    public function reconcile(DropboxSource $source, array $entries, array $issues, array $ignoredSheets = []): void
    {
        $bySlot = collect($entries)->keyBy(fn ($entry) => $entry['sheet'].'!'.$entry['slot']);
        $byContent = collect($entries)->groupBy(fn ($entry) => $entry['sheet'].'!'.$entry['fingerprint']);
        $appearances = DropboxAppearance::where('source_id', $source->id)->get()->keyBy(fn ($appearance) => $appearance->sheet.'!'.$appearance->slot);
        $domains = DropboxConnection::find($source->connection_id)?->option('domains') ?? [];
        $ignored = collect($ignoredSheets)->pluck('sheet')->all();
        $hints = DropboxConflict::where('source_id', $source->id)->whereIn('reason', ['mapping_required', 'ambiguous_row'])->whereIn('state', ['open', 'rechecking'])->get();
        foreach ($hints as $conflict) {
            $snapshot = $conflict->snapshot;
            $sheet = $snapshot['sheet'] ?? '';
            $slot = (string) ($snapshot['slot'] ?? '');
            $fingerprint = $snapshot['fingerprint'] ?? null;
            if (! $fingerprint || ! in_array($snapshot['domain'] ?? '', $domains, true) || in_array($sheet, $ignored, true)) {
                continue;
            }
            $current = $bySlot->get($sheet.'!'.$slot);
            if ($current && $current['fingerprint'] === $fingerprint) {
                continue; // Its current mapping still failed; do not hide a genuine issue.
            }
            $matches = $byContent->get($sheet.'!'.$fingerprint, collect());
            if ($matches->isNotEmpty()) {
                if ($matches->count() !== 1) {
                    continue;
                }
                $entry = $matches->first();
                $appearance = $appearances->get($entry['sheet'].'!'.$entry['slot']);
                if (! $appearance || $appearance->fingerprint !== $entry['fingerprint']) {
                    $currentHints = $hints->filter(fn ($hint) => $hint->id !== $conflict->id
                        && in_array($hint->state, ['open', 'rechecking'], true)
                        && ($hint->snapshot['sheet'] ?? '') === $entry['sheet']
                        && (string) ($hint->snapshot['slot'] ?? '') === (string) $entry['slot']
                        && ($hint->snapshot['fingerprint'] ?? '') === $entry['fingerprint']);
                    if ($currentHints->isEmpty()) {
                        continue;
                    }
                    $reviewed = $currentHints->pluck('decision')->push($conflict->decision)->filter()->unique(fn ($decision) => WorkbookReader::fingerprint($decision));
                    if ($reviewed->count() > 1) {
                        continue; // Different explicit mappings remain reviewable instead of selecting one.
                    }
                    if ($conflict->decision) {
                        foreach ($currentHints as $hint) {
                            if (! $hint->decision && ($hint->snapshot['generation'] ?? null) === ($snapshot['generation'] ?? null)) {
                                $hint->update(['decision' => $conflict->decision, 'resolved_by' => $conflict->resolved_by]);
                            }
                        }
                    }
                }
            } elseif (collect($issues)->contains(function ($issue) use ($sheet, $slot) {
                if (($issue['sheet'] ?? '') !== $sheet) {
                    return false;
                }
                $row = preg_replace('/\D/', '', $slot);

                return ! isset($issue['row']) || (string) $issue['row'] === $row;
            })) {
                continue;
            }
            $conflict->update(['state' => 'resolved']);
        }
    }
}
