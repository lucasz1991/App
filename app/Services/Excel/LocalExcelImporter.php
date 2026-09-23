<?php

namespace App\Services\Excel;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\LocalExcelImport;
use App\Models\User;
use App\Services\Dropbox\ConflictStore;
use App\Services\Dropbox\ConnectionManager;
use App\Services\Dropbox\DomainAdapter;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\SyncContext;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LocalExcelImporter
{
    public function authorize(?User $actor): void
    {
        app(ConnectionManager::class)->authorize($actor);
    }

    public function connection(): DropboxConnection
    {
        return DropboxConnection::firstOrCreate(['account_id' => 'railtime:local-excel'], [
            'account_label' => 'Lokaler Excel-Import', 'mode' => DropboxMode::Import,
            'settings' => [...config('dropbox.defaults'), 'source' => 'local', 'folders' => ['/local-import']],
        ]);
    }

    public function prepare(string $bytes, string $name, string $profile, User $actor): LocalExcelImport
    {
        $this->authorize($actor);
        validator(['name' => $name, 'profile' => $profile], ['name' => ['required', 'string', 'max:200', 'regex:/^[^\\\\\/\x00-\x1F]+\.xlsx$/iuD'], 'profile' => 'required|in:weekly,matrix'])->validate();
        if (strlen($bytes) > 12 * 1024 * 1024) {
            throw ValidationException::withMessages(['upload' => 'Die Datei darf höchstens 12 MB groß sein.']);
        }
        // Large historic workbooks need more memory while PhpSpreadsheet reads.
        // This affects only the current import request, never the global PHP config.
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1024M');
        }
        $parsed = app(WorkbookReader::class)->read($bytes, $profile);
        if (! $parsed['rows']) {
            throw ValidationException::withMessages(['upload' => 'Keine passenden Daten gefunden. Dateityp und Tabellenüberschriften prüfen.']);
        }

        return Cache::store('file')->lock('local-excel-import', 330)->block(2, function () use ($bytes, $name, $profile, $actor, $parsed) {
            if (LocalExcelImport::where('status', 'running')->exists()) {
                throw ValidationException::withMessages(['upload' => 'Den laufenden Import zuerst abschließen oder pausieren.']);
            }
            $base = 'private/local-excel/'.Str::uuid();
            $json = json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (! Storage::disk('local')->put($base.'.xlsx', $bytes) || ! Storage::disk('local')->put($base.'.json', $json)) {
                throw ValidationException::withMessages(['upload' => 'Die Importdatei konnte nicht privat gespeichert werden.']);
            }

            return SyncContext::import(fn () => DB::transaction(function () use ($bytes, $name, $profile, $actor, $parsed, $base, $json) {
                $connection = $this->connection();
                $source = DropboxSource::firstOrNew(['connection_id' => $connection->id, 'file_id' => 'local:'.hash('sha256', WorkbookReader::normalize($name).':'.$profile)]);
                $source->forceFill(['path' => '/local-import/'.$name, 'name' => $name, 'profile' => $profile, 'rev' => hash('sha256', $bytes), 'weeks' => $parsed['weeks'], 'progress' => null, 'state' => 'preview', 'first_seen_at' => $source->first_seen_at ?? now()])->save();
                $ids = [];
                foreach ($parsed['rows'] as $entry) {
                    $subject = $entry['locator']['subject'] ?? $entry['values']['employee'] ?? '';
                    if ($subject === '') {
                        continue;
                    }
                    $key = WorkbookReader::normalize($subject);
                    if (! isset($ids[$key])) {
                        $identity = app(DomainAdapter::class)->identity($connection, $subject, ($entry['locator']['kind'] ?? '') === 'provider' ? 'provider' : 'unresolved');
                        $ids[$key] = $identity->id;
                    }
                }

                return LocalExcelImport::updateOrCreate(['source_id' => $source->id], [
                    'created_by' => $actor->id, 'file_hash' => hash('sha256', $bytes), 'disk_path' => $base.'.xlsx',
                    'parsed_path' => $base.'.json', 'parsed_hash' => hash('sha256', $json), 'status' => 'preview', 'processed' => 0, 'error' => null,
                    'summary' => ['rows' => count($parsed['rows']), 'counts' => array_count_values(array_column($parsed['rows'], 'domain')), 'weeks' => $parsed['weeks'], 'issues' => $parsed['issues'], 'identity_ids' => array_values($ids)],
                ]);
            }));
        });
    }

    public function start(int $id, User $actor): void
    {
        $this->authorize($actor);
        Cache::store('file')->lock('local-excel-import', 330)->block(2, function () use ($id) {
            if (LocalExcelImport::where('id', '!=', $id)->where('status', 'running')->exists()) {
                throw ValidationException::withMessages(['import' => 'Ein anderer Import läuft bereits.']);
            }
            $import = LocalExcelImport::findOrFail($id);
            if (! in_array($import->status, ['preview', 'paused', 'failed', 'done'], true)) {
                return;
            }
            if ($import->status === 'done') {
                $import->source->update(['progress' => null]);
                $import->processed = 0;
            }
            $import->forceFill(['status' => 'running', 'error' => null])->save();
        });
    }

    public function pause(int $id, User $actor): void
    {
        $this->authorize($actor);
        Cache::store('file')->lock('local-excel-import', 330)->block(2, fn () => LocalExcelImport::whereKey($id)->where('status', 'running')->update(['status' => 'paused']));
    }

    public function advance(int $id, User $actor): void
    {
        $this->authorize($actor);
        $lock = Cache::store('file')->lock('local-excel-import', 330);
        if (! $lock->get()) {
            return;
        }
        try {
            $import = LocalExcelImport::findOrFail($id);
            if ($import->status !== 'running') {
                return;
            }
            $source = $import->source;
            $connection = DropboxConnection::findOrFail($source->connection_id);
            $bytes = Storage::disk('local')->get($import->disk_path);
            $json = Storage::disk('local')->get($import->parsed_path);
            if (! is_string($bytes) || ! is_string($json) || ! hash_equals($import->file_hash, hash('sha256', $bytes)) || ! hash_equals($import->parsed_hash, hash('sha256', $json))) {
                throw ValidationException::withMessages(['import' => 'Importdatei fehlt oder wurde verändert. Die Originaldatei erneut auswählen und prüfen.']);
            }
            $sync = new FileSynchronizer(new LocalWorkbookClient($source, $bytes), app(DomainAdapter::class), new LocalImportGuard, app(ConflictStore::class), app(WorkLedger::class), 'file');
            // Local chunks have no external writes. Commit their data and cursor
            // together instead of flushing a separate disk transaction per cell.
            DB::transaction(function () use ($sync, $connection, $source, $json, $import) {
                SyncContext::import(fn () => $sync->sync($connection, $source, false, json_decode($json, true, 512, JSON_THROW_ON_ERROR)));
                $source->refresh();
                $import->forceFill(['processed' => $source->progress['next'] ?? $import->summary['rows'], 'status' => $source->progress ? 'running' : 'done', 'error' => null])->save();
            });
        } catch (\Throwable $e) {
            if (isset($import) && $import->status === 'running') {
                $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : 'Import konnte nicht fortgesetzt werden. Datei und Zuordnungen prüfen und erneut starten.';
                $import->update(['status' => 'failed', 'error' => $message]);
            } else {
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    public function assign(int $identityId, string $target, User $actor, ?string $password = null): ?User
    {
        $this->authorize($actor);

        return Cache::store('file')->lock('local-excel-import', 330)->block(2, fn () => SyncContext::import(fn () => DB::transaction(function () use ($identityId, $target, $password) {
            if (LocalExcelImport::where('status', 'running')->exists()) {
                throw ValidationException::withMessages(['mapping' => 'Den Import vor Änderungen an Zuordnungen pausieren.']);
            }
            $identity = DropboxIdentity::where('connection_id', $this->connection()->id)->lockForUpdate()->findOrFail($identityId);
            if (DropboxAppearance::whereIn('record_id', DropboxRecord::where('connection_id', $identity->connection_id)->where('domain', 'planning')->select('id'))->where('baseline->employee', $identity->details['display_name'] ?? '')->exists()
                && ($identity->kind !== 'unresolved') && $target !== ($identity->kind === 'provider' ? 'provider' : (string) $identity->user_id)) {
                throw ValidationException::withMessages(['mapping' => 'Für diese Bezeichnung wurden bereits Einsätze importiert. Bestehende Zuordnungen in der Schichtplanung ändern.']);
            }
            $user = null;
            if ($target === 'new') {
                abort_unless(app()->environment(['local', 'testing']) && $identity->kind === 'unresolved', 403);
                validator(['password' => $password], ['password' => 'required|string|min:12|max:200'])->validate();
                $email = 'excel-test-'.$identity->id.'@railtime.invalid';
                if (User::where('email', $email)->exists()) {
                    throw ValidationException::withMessages(['mapping' => 'Dieses Testkonto existiert bereits. In der Liste auswählen.']);
                }
                $user = User::create(['name' => $identity->details['display_name'] ?? $identity->alias, 'email' => $email, 'password' => Hash::make($password), 'role' => 'staff', 'status' => true, 'locale' => 'de']);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif ($target !== 'provider') {
                $user = User::where('role', 'staff')->where('status', true)->find((int) $target);
                if (! $user) {
                    throw ValidationException::withMessages(['mapping' => 'Ein aktives Mitarbeiterkonto oder Dienstleister auswählen.']);
                }
            }
            $identity->update(['kind' => $user ? 'employee' : 'provider', 'user_id' => $user?->id, 'revision' => $identity->revision + 1]);

            return $user;
        })));
    }

    public function conflicts(int $sourceId)
    {
        return DropboxConflict::where('source_id', $sourceId)->whereIn('state', ['open', 'rechecking']);
    }
}
