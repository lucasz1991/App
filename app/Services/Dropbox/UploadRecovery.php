<?php

namespace App\Services\Dropbox;

use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use Illuminate\Support\Facades\DB;

class UploadRecovery
{
    public function recover(DropboxConnection $connection, DropboxSource $source, array $metadata): void
    {
        if (empty($metadata['content_hash'])) {
            return;
        }
        $upload = DB::table('dropbox_uploads')->where('connection_id', $connection->id)->where('generation', $connection->generation)
            ->where('path', mb_strtolower($source->path))->where('content_hash', $metadata['content_hash'])->whereIn('status', ['prepared', 'uncertain', 'confirmed'])->latest('id')->first();
        if (! $upload) {
            return;
        }
        DB::transaction(function () use ($connection, $source, $metadata, $upload) {
            app(SyncGuard::class)->current($connection, lock: true);
            foreach (json_decode($upload->manifest ?? '[]', true) as $change) {
                $entry = $change['entry'];
                $appearance = isset($change['appearance_id']) ? DropboxAppearance::find($change['appearance_id']) : null;
                $appearance ??= DropboxAppearance::firstOrNew(['source_id' => $source->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot']]);
                $appearance->forceFill(['record_id' => $change['record_id'] ?? $appearance->record_id, 'locator' => $entry['locator'], 'baseline' => $change['values'], 'last_excel' => $change['values'], 'fingerprint' => WorkbookReader::fingerprint($change['values']), 'seen_rev' => $metadata['rev']])->save();
            }
            $source->update(['own_rev' => $metadata['rev']]);
            DB::table('dropbox_uploads')->where('id', $upload->id)->update(['status' => 'recovered', 'result_rev' => $metadata['rev'], 'updated_at' => now()]);
        });
    }
}
