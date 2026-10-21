<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;

class ConflictStore
{
    public function put(DropboxConnection $connection, ?DropboxSource $source, ?DropboxRecord $record, string $reason, array $snapshot, string $slot = ''): DropboxConflict
    {
        $key = hash('sha256', implode(':', [$connection->id, $source?->id, $record?->id, $reason, $slot]));
        $conflict = DropboxConflict::firstOrNew(['key' => $key]);
        $old = $conflict->snapshot;
        if ($reason === 'not_representable' && $old === $snapshot && $conflict->state === 'acknowledged') {
            return $conflict;
        }
        if (! $this->sameReviewedContent($reason, $old, $snapshot)) {
            $conflict->decision = null;
            $conflict->resolved_by = null;
        }
        $conflict->forceFill(['connection_id' => $connection->id, 'source_id' => $source?->id, 'record_id' => $record?->id, 'reason' => $reason, 'snapshot' => $snapshot, 'state' => 'open'])->save();

        return $conflict;
    }

    private function sameReviewedContent(string $reason, ?array $old, array $snapshot): bool
    {
        if ($old === null || $reason !== 'field_conflict') {
            return $old === $snapshot;
        }

        return $this->reviewedContent($old) === $this->reviewedContent($snapshot);
    }

    private function reviewedContent(array $snapshot): array
    {
        unset($snapshot['rev']);
        if (isset($snapshot['appearances'])) {
            $snapshot['appearances'] = array_map(function ($appearance) {
                unset($appearance['origin']);

                return $appearance;
            }, $snapshot['appearances']);
            usort($snapshot['appearances'], fn ($left, $right) => strcmp(WorkbookReader::fingerprint($left), WorkbookReader::fingerprint($right)));
        }
        foreach ($snapshot['fields'] ?? [] as $field => $versions) {
            $versions = array_map(function ($version) {
                unset($version['origin']);
                if (isset($version['values']) && is_array($version['values'])) {
                    usort($version['values'], fn ($left, $right) => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
                }

                return $version;
            }, $versions);
            usort($versions, fn ($left, $right) => strcmp(WorkbookReader::fingerprint($left), WorkbookReader::fingerprint($right)));
            $snapshot['fields'][$field] = $versions;
        }

        return $snapshot;
    }
}
