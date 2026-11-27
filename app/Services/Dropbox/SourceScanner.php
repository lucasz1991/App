<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SourceScanner
{
    public function __construct(private DropboxClient $client, private WorkLedger $ledger, private SyncGuard $guard) {}

    public function scan(DropboxConnection $connection, bool $preview = false): void
    {
        $preview = $preview || $connection->mode === DropboxMode::Preview;
        $this->guard->current($connection, preview: $preview);
        foreach ($connection->option('folders') as $path) {
            $folder = DB::table('dropbox_folders')->where('connection_id', $connection->id)->where('path', $path)->where('generation', $connection->generation)->where('preview', $preview)->first();
            if (! $folder) {
                $id = DB::table('dropbox_folders')->insertGetId(['connection_id' => $connection->id, 'path' => $path, 'generation' => $connection->generation, 'preview' => $preview, 'created_at' => now(), 'updated_at' => now()]);
                $folder = DB::table('dropbox_folders')->find($id);
            }
            $cursor = $folder->cursor;
            for ($page = 0; $page < 20; $page++) {
                $this->guard->current($connection, preview: $preview);
                try {
                    $result = $this->client->rpc($connection, $cursor ? 'files/list_folder/continue' : 'files/list_folder', $cursor ? ['cursor' => $cursor] : ['path' => $path === '/' ? '' : $path, 'recursive' => true, 'include_deleted' => true, 'limit' => 2000]);
                } catch (DropboxApiException $e) {
                    if ($e->reason !== 'cursor_reset') {
                        throw $e;
                    }
                    $cursor = null;
                    DB::table('dropbox_folders')->where('id', $folder->id)->update(['cursor' => null]);

                    continue;
                }
                DB::transaction(function () use ($connection, $preview, $result, $folder) {
                    $this->guard->current($connection, preview: $preview, lock: true);
                    foreach ($result['entries'] as $entry) {
                        $this->register($connection, $entry, $preview);
                    }
                    DB::table('dropbox_folders')->where('id', $folder->id)->update(['cursor' => $result['cursor'], 'updated_at' => now()]);
                });
                $cursor = $result['cursor'];
                if (! $result['has_more']) {
                    break;
                }
                if ($page === 19) {
                    $this->ledger->enqueue($connection, 'scan', ($preview ? 'preview:' : '').'scan', ['preview' => $preview], 0);
                }
            }
        }
        $explicit = $connection->option('additional_sources');
        if ($connection->option('matrix_path')) {
            $explicit[] = ['path' => $connection->option('matrix_path'), 'profile' => 'matrix'];
        }
        foreach ($explicit as $configured) {
            try {
                $entry = $this->client->rpc($connection, 'files/get_metadata', ['path' => $configured['path']]);
                DB::transaction(fn () => $this->register($connection, $entry, $preview));
                DropboxConflict::where('connection_id', $connection->id)->where('reason', 'matrix_missing')->get()->filter(fn ($c) => ($c->snapshot['path'] ?? '') === $configured['path'])->each(fn ($c) => $c->update(['state' => 'resolved']));
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                } app(ConflictStore::class)->put($connection, null, null, 'matrix_missing', ['path' => $configured['path']], $configured['path']);
            }
        }
        // Revisit unchanged files for parser upgrades and when their next
        // import-owned draft becomes historical; no upload event is required.
        if (! $preview) {
            foreach (DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->get() as $source) {
                $dueAt = $source->last_report['next_history_due_at'] ?? null;
                $historyDue = $connection->option('historical_auto_complete') === true
                    && is_string($dueAt) && $dueAt !== '' && CarbonImmutable::parse($dueAt)->lte(CarbonImmutable::now());
                if (($source->last_report['parser_version'] ?? null) !== SourceSyncReport::VERSION || $historyDue) {
                    $this->ledger->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
                }
            }
        }
        DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update(['checked_at' => now()]);
    }

    public function register(DropboxConnection $connection, array $entry, bool $preview = false): ?DropboxSource
    {
        $path = $entry['path_lower'] ?? $entry['path_display'] ?? '';
        if (($entry['.tag'] ?? '') === 'deleted') {
            return $this->registerDeletion($connection, $path, $preview);
        }
        if (($entry['.tag'] ?? 'file') !== 'file' || ! $this->guard->pathAllowed($connection, $path)) {
            return null;
        }
        $source = DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->first();
        if ($source && isset($source->last_report['replacement_source_id'])) {
            return null; // Historical cursor entries cannot reactivate an audited replacement.
        }
        $isMatrix = $connection->option('matrix_path') && mb_strtolower($connection->option('matrix_path')) === mb_strtolower($path);
        $configured = collect($connection->option('additional_sources'))->first(fn ($s) => mb_strtolower($s['path']) === mb_strtolower($path));
        $isMatrix = $isMatrix || ($configured['profile'] ?? '') === 'matrix';
        $match = app(WeekFileMatcher::class)->match($entry['name'], $connection->option('filename_rule'));
        if (! $isMatrix && ! $match && ! $configured) {
            if ($source) {
                $source->update(['state' => 'excluded']);
            }

            return null;
        }
        if (! $source && ! $connection->option('auto_discover') && ! $isMatrix && ! $configured) {
            return null;
        }
        if (! $source) {
            $source = $this->replacement($connection, $path, $isMatrix ? 'matrix' : 'weekly', $entry, $preview);
            if ($preview && $source) {
                $this->ledger->enqueue($connection, 'file', 'preview:file:'.$source->id, ['source_id' => $source->id, 'preview' => true]);

                return $source;
            }
        }
        $source ??= new DropboxSource(['connection_id' => $connection->id, 'file_id' => $entry['id'], 'first_seen_at' => now()]);
        if (in_array($source->state, ['missing', 'excluded'], true)) {
            $source->state = 'new';
            $source->processed_rev = null;
        }
        $source->fill(['path' => $path, 'name' => $entry['name'], 'profile' => $isMatrix ? 'matrix' : 'weekly', 'rev' => $entry['rev'], 'server_modified' => $entry['server_modified'] ?? null]);
        $source->save();
        DropboxConflict::where('source_id', $source->id)->where('reason', 'source_missing')->update(['state' => 'resolved']);
        if (! $preview && ! $connection->option('watch_history') && $source->weeks && max($source->weeks) < now($connection->option('timezone'))->format('o-\WW')) {
            return $source;
        }
        if ($source->processed_rev !== $source->rev || $preview) {
            $this->ledger->enqueue($connection, 'file', ($preview ? 'preview:' : '').'file:'.$source->id, ['source_id' => $source->id, 'preview' => $preview]);
        }

        return $source;
    }

    private function registerDeletion(DropboxConnection $connection, string $path, bool $preview): ?DropboxSource
    {
        $path = mb_strtolower(rtrim($path, '/'));
        if ($path === '') {
            return null;
        }
        $prefix = $path.'/';
        $pattern = strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $sources = DropboxSource::where('connection_id', $connection->id)
            ->where(fn ($query) => $query->whereRaw('LOWER(path) = ?', [$path])
                ->orWhereRaw("LOWER(path) LIKE ? ESCAPE '!'", [$pattern]))
            ->get()->filter(function (DropboxSource $source) use ($connection, $path, $prefix) {
                $sourcePath = mb_strtolower($source->path);

                return ($sourcePath === $path || str_starts_with($sourcePath, $prefix))
                    && ! isset($source->last_report['replacement_source_id'])
                    && $this->guard->pathAllowed($connection, $sourcePath);
            });
        if ($sources->isEmpty() && ! $this->guard->pathAllowed($connection, $path)) {
            return null;
        }

        // Cursor deletions can arrive after another file or folder has appeared at the same path.
        $current = $this->metadataAt($connection, $path);
        $kind = $current ? ($current['.tag'] ?? 'file') : null;
        $registered = $kind === 'file' ? $this->register($connection, $current, $preview) : null;
        foreach ($sources->groupBy(fn (DropboxSource $source) => mb_strtolower($source->path)) as $sourcePath => $matches) {
            $live = $sourcePath === $path ? $current : ($kind === 'folder' ? $this->metadataAt($connection, $sourcePath) : null);
            $isFile = $live && ($live['.tag'] ?? 'file') === 'file';
            $found = $sourcePath === $path ? $registered : ($isFile ? $this->register($connection, $live, $preview) : null);
            foreach ($matches as $source) {
                if ($found?->is($source) || ($isFile && ($live['id'] ?? null) === $source->file_id)) {
                    continue;
                }
                $source->update(['state' => 'missing']);
                app(ConflictStore::class)->put($connection, $source, null, 'source_missing', ['path' => $source->path]);
            }
        }

        return $registered;
    }

    private function metadataAt(DropboxConnection $connection, string $path): ?array
    {
        try {
            return $this->client->rpc($connection, 'files/get_metadata', ['path' => $path]);
        } catch (DropboxApiException $exception) {
            if ($exception->reason !== 'not_found') {
                throw $exception;
            }

            return null;
        }
    }

    public function sourceForPreview(DropboxConnection $connection, DropboxSource $source): ?DropboxSource
    {
        if (! $this->guard->pathAllowed($connection, $source->path) || isset($source->last_report['replacement_source_id'])) {
            return null;
        }
        $entry = $this->metadataAt($connection, $source->path);
        if (($entry['.tag'] ?? 'file') !== 'file' || empty($entry['id'])) {
            return null;
        }
        if ($entry['id'] === $source->file_id) {
            return in_array($source->state, ['missing', 'excluded'], true) ? null : $source;
        }
        $candidate = $this->replacement($connection, $source->path, $source->profile, $entry, true);
        if (! $candidate?->is($source)) {
            return null;
        }

        // Keep the source key for the comparison against its stored baseline, but use the new file only for this preview.
        return $source->forceFill(['file_id' => $entry['id'], 'state' => 'new']);
    }

    private function replacement(DropboxConnection $connection, string $path, string $profile, array $entry, bool $preview): ?DropboxSource
    {
        $candidates = DropboxSource::where('connection_id', $connection->id)->where('path', $path)->where('profile', $profile)->get()
            ->reject(fn ($source) => isset($source->last_report['replacement_source_id']));
        if ($candidates->count() !== 1) {
            return null;
        }
        $candidate = $candidates->first();
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-file:'.$connection->id.':'.$candidate->file_id, 330);
        if (! $lock->get()) {
            throw new DropboxApiException('file_busy', 5);
        }
        try {
            try {
                $this->client->rpc($connection, 'files/get_metadata', ['path' => $candidate->file_id]);

                return null; // A moved or still-active file keeps its own baseline.
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                }
            }
            if ($preview) {
                return $candidate;
            }

            return DB::transaction(function () use ($connection, $candidate, $entry, $path, $profile) {
                $this->guard->current($connection, lock: true);
                if (DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->exists()) {
                    return DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->first();
                }
                $fresh = DropboxSource::lockForUpdate()->findOrFail($candidate->id);
                if ($fresh->path !== $path || $fresh->profile !== $profile || $fresh->file_id !== $candidate->file_id) {
                    throw new DropboxApiException('file_busy', 5);
                }
                // Dropbox revisions belong to a file ID. Preserve the common row
                // baselines but discard every progress/own-revision shortcut.
                $fresh->forceFill(['file_id' => $entry['id'], 'state' => 'new', 'processed_rev' => null, 'own_rev' => null, 'progress' => null])->save();

                return $fresh;
            });
        } finally {
            $lock->release();
        }
    }
}
