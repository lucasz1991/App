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
        foreach ($conflicts as $conflict) {
            $snapshot = $conflict->snapshot;
            if (($snapshot['generation'] ?? null) !== $connection->generation || ($snapshot['sheet'] ?? '') !== $entry['sheet'] || (string) ($snapshot['slot'] ?? '') !== (string) $entry['slot'] || ($snapshot['fingerprint'] ?? '') !== $entry['fingerprint']) {
                continue;
            }
            $record = DropboxRecord::where('connection_id', $connection->id)->where('domain', $entry['domain'])->find($conflict->decision['record_id'] ?? null);
            if (! $record) {
                continue;
            }
            $appearance = DropboxAppearance::where('source_id', $source->id)->where('sheet', $entry['sheet'])->where('record_id', $record->id)->first();
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
}
