<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingRenderStatus;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\MarketingRender;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingFileSourceSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class MarketingFileSourceService
{
    public const FILE_PATTERN = '#(?<![A-Za-z0-9:+./?&=%_-])(?:https?://[^\s"\')>]+)?/administrator/marketing/dateien/([1-9][0-9]*)(?:\?v=[a-f0-9]{8,64})?(?=$|[\s"\')>])#i';

    /** @var array<string, true> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
    ];

    private const MAX_FOLDER_DEPTH = 250;

    /** @var array<int, true> */
    private static array $forcedFileDeletes = [];

    /** @var array<int, true> */
    private static array $forcedFolderDeletes = [];

    public function sourcePool(): FilePool
    {
        return FilePool::company();
    }

    public function selectedFolderId(bool $uncached = false): ?int
    {
        return MarketingFileSourceSettings::selectedFolderId($uncached);
    }

    public function selectionFingerprint(bool $uncached = true): string
    {
        return MarketingFileSourceSettings::fingerprintForFolderId($this->selectedFolderId($uncached));
    }

    public function selectedFolder(): ?FileFolder
    {
        $folderId = $this->selectedFolderId(true);
        if ($folderId === null || $folderId < 1) {
            return null;
        }

        $pool = $this->sourcePool();
        $folder = FileFolder::query()->find($folderId);

        return $folder && $this->folderChain($folder, $pool) !== null
            ? $folder
            : null;
    }

    public function hasInvalidSelection(): bool
    {
        $folderId = $this->selectedFolderId(true);

        return $folderId !== null && ($folderId < 1 || $this->selectedFolder() === null);
    }

    /**
     * @return list<array{id: int, parent_id: ?int, name: string, path: string, depth: int, selected: bool}>
     */
    public function folderTree(): array
    {
        $pool = $this->sourcePool();
        $selected = $this->selectedFolderId(true);

        return FileFolder::query()
            ->where('file_pool_id', $pool->id)
            ->orderBy('name')
            ->get()
            ->map(function (FileFolder $folder) use ($pool, $selected): ?array {
                $chain = $this->folderChain($folder, $pool);
                if ($chain === null) {
                    return null;
                }

                return [
                    'id' => (int) $folder->id,
                    'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
                    'name' => (string) $folder->name,
                    'path' => $chain->pluck('name')->implode(' / '),
                    'depth' => max(0, $chain->count() - 1),
                    'selected' => (int) $selected === (int) $folder->id,
                ];
            })
            ->filter()
            ->sortBy(fn (array $folder): string => mb_strtolower($folder['path']))
            ->values()
            ->all();
    }

    public function setSelectedFolder(
        ?int $folderId,
        User $actor,
        ?string $expectedSelectionFingerprint = null,
    ): void {
        abort_unless($actor->isAdmin(), 403);

        $pool = $this->sourcePool();
        DB::transaction(function () use ($folderId, $expectedSelectionFingerprint, $pool): void {
            Setting::query()->firstOrCreate(
                [
                    'type' => MarketingFileSourceSettings::GROUP,
                    'key' => MarketingFileSourceSettings::KEY,
                ],
                ['value' => ['selected_folder_id' => null]],
            );

            $setting = Setting::query()
                ->where('type', MarketingFileSourceSettings::GROUP)
                ->where('key', MarketingFileSourceSettings::KEY)
                ->lockForUpdate()
                ->firstOrFail();
            $current = MarketingFileSourceSettings::folderIdFromStoredRaw($setting->getRawOriginal('value'));
            $currentFingerprint = MarketingFileSourceSettings::fingerprintForFolderId($current);

            if ($expectedSelectionFingerprint !== null
                && ! hash_equals($currentFingerprint, strtolower($expectedSelectionFingerprint))) {
                throw ValidationException::withMessages([
                    'mediaFolderId' => 'Die Marketing-Bildquelle wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                ]);
            }

            FileFolder::query()
                ->where('file_pool_id', $pool->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($folderId !== null) {
                $folder = FileFolder::query()->find($folderId);
                if (! $folder || $this->folderChain($folder, $pool) === null) {
                    throw ValidationException::withMessages([
                        'mediaFolderId' => 'Der gewählte Dateiordner gehört nicht zum Firmen-Dateipool oder ist nicht mehr verfügbar.',
                    ]);
                }
            }

            $this->assertAllReferencesAllowedForSource($folderId, lockRows: true);

            if ($current === $folderId) {
                return;
            }

            $setting->forceFill(['value' => ['selected_folder_id' => $folderId]])->save();
        });

        Cache::forget('settings.'.MarketingFileSourceSettings::GROUP.'.'.MarketingFileSourceSettings::KEY);
    }

    /**
     * @return array{
     *   assets: list<array{src: string, name: string, type: string, mime_type: string, animated: bool, category: string, width: int, height: int}>,
     *   total: int,
     *   limit: int,
     *   truncated: bool
     * }
     */
    public function editorAssetLibrary(): array
    {
        if ($this->hasInvalidSelection()) {
            return ['assets' => [], 'total' => 0, 'limit' => 0, 'truncated' => false];
        }

        $limit = max(1, min(1000, (int) config('marketing.assets.editor_limit', 500)));
        $assets = [];
        $total = 0;
        foreach ($this->candidateFiles() as $file) {
            try {
                // candidateFiles() has already applied the current source scope.
                // Only validate the bounded private bytes here, so the editor does
                // not repeat the source/folder query chain for every thumbnail.
                $snapshot = $this->readAndValidate($file);
            } catch (Throwable) {
                continue;
            }

            $total++;
            if (count($assets) < $limit) {
                $assets[] = [
                    'src' => route('admin.marketing.files.show', $file).'?v='.substr($snapshot['sha256'], 0, 16),
                    'name' => $snapshot['name'],
                    'type' => 'image',
                    'mime_type' => $snapshot['mime_type'],
                    'animated' => $snapshot['mime_type'] === 'image/gif',
                    'category' => $this->folderPath($file->folder) ?: 'Grundverzeichnis',
                    'width' => $snapshot['width'],
                    'height' => $snapshot['height'],
                ];
            }
        }

        return [
            'assets' => $assets,
            'total' => $total,
            'limit' => $limit,
            'truncated' => $total > $limit,
        ];
    }

    /** @return list<array{src: string, name: string, type: string, mime_type: string, animated: bool, category: string, width: int, height: int}> */
    public function editorAssets(): array
    {
        return $this->editorAssetLibrary()['assets'];
    }

    public function editorAssetCount(): int
    {
        return $this->editorAssetLibrary()['total'];
    }

    /**
     * @return array{id: int, name: string, contents: string, mime_type: string, size: int, width: int, height: int, sha256: string}
     */
    public function validatedSnapshot(File $file): array
    {
        if (! $this->fileIsAllowed($file)) {
            abort(404);
        }

        return $this->readAndValidate($file);
    }

    public function fileIsAllowed(File $file): bool
    {
        $selected = $this->selectedFolderId(true);
        if ($selected !== null && $selected < 1) {
            return false;
        }

        return $this->fileIsInScope($file, $selected);
    }

    public function fileIsInScope(File $file, ?int $folderId): bool
    {
        if (! $this->fileBelongsToCompanyPool($file)
            || trim((string) $file->disk) !== 'private'
            || ! $file->isWithinVisibilityWindow()
            || $file->isExpiredForDeletion()) {
            return false;
        }

        return $this->folderIdIsInScope($file->folder_id ? (int) $file->folder_id : null, $folderId);
    }

    /**
     * Serializes source changes with file/folder mutations and render snapshots.
     * Callers must already be inside a database transaction.
     */
    public function lockSourceSelection(): ?int
    {
        $setting = Setting::query()
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->lockForUpdate()
            ->first();

        return MarketingFileSourceSettings::folderIdFromStoredRaw(
            $setting?->getRawOriginal('value'),
            $setting !== null,
        );
    }

    /**
     * @param  list<int>  $fileIds
     * @return Collection<int, File>
     */
    public function lockFilesForUpdate(array $fileIds): Collection
    {
        $fileIds = array_values(array_unique(array_map(
            'intval',
            array_filter($fileIds, static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
        sort($fileIds);
        if ($fileIds === []) {
            return collect();
        }

        $files = File::query()
            ->whereIn('id', $fileIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($files->count() !== count($fileIds)) {
            throw ValidationException::withMessages([
                'file' => 'Eine verwendete Marketing-Datei ist nicht mehr vorhanden.',
            ]);
        }

        return $files;
    }

    public function sourceFingerprint(): string
    {
        $poolId = (int) $this->sourcePool()->id;
        $folderId = $this->selectedFolderId(true);

        if ($folderId === null) {
            return 'pool:'.$poolId.':root';
        }

        if ($folderId < 1 || $this->selectedFolder() === null) {
            return 'pool:'.$poolId.':invalid:'.$folderId;
        }

        return 'pool:'.$poolId.':folder:'.$folderId;
    }

    /** @return list<int> */
    public function referencedFileIds(string ...$documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            preg_match_all(self::FILE_PATTERN, $document, $matches);
            foreach ($matches[1] ?? [] as $id) {
                $ids[(int) $id] = true;
            }
        }

        $result = array_keys($ids);
        sort($result);

        return $result;
    }

    public function fileIdFromUrl(string $url): ?int
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '' || str_contains($url, '\\')) {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            $candidate = parse_url($url);
            $application = parse_url((string) config('app.url'));
            if (! is_array($candidate)
                || ! is_array($application)
                || strtolower((string) ($candidate['scheme'] ?? '')) !== strtolower((string) ($application['scheme'] ?? ''))
                || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($application['host'] ?? ''))
                || (int) ($candidate['port'] ?? 0) !== (int) ($application['port'] ?? 0)
                || isset($candidate['user'])
                || isset($candidate['pass'])
                || isset($candidate['fragment'])) {
                return null;
            }

            $path = (string) ($candidate['path'] ?? '');
            $query = (string) ($candidate['query'] ?? '');
        } else {
            $parts = parse_url($url);
            if (! is_array($parts)
                || isset($parts['scheme'])
                || isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['fragment'])) {
                return null;
            }
            $path = (string) ($parts['path'] ?? '');
            $query = (string) ($parts['query'] ?? '');
        }

        if ($query !== '' && ! preg_match('/^v=[a-f0-9]{8,64}$/i', $query)) {
            return null;
        }

        return preg_match('#^/administrator/marketing/dateien/([1-9][0-9]*)$#', $path, $matches)
            ? (int) $matches[1]
            : null;
    }

    public function urlIsAllowed(string $url): bool
    {
        $fileId = $this->fileIdFromUrl($url);
        if ($fileId === null) {
            return false;
        }

        $file = File::query()->find($fileId);
        if (! $file || ! $this->fileIsAllowed($file)) {
            return false;
        }

        try {
            $this->readAndValidate($file);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertFileCanBeDeleted(File $file): void
    {
        if (isset(self::$forcedFileDeletes[(int) $file->id])) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            $this->lockSourceSelection();
        }

        $creativeIds = $this->creativeIdsReferencingFile($file);
        $this->lockCreativeRows($creativeIds);
        if ($this->creativeIdsReferencingFile($file) !== []) {
            throw ValidationException::withMessages([
                'file' => 'Diese Datei wird noch in mindestens einem Marketing-Motiv verwendet und kann nicht gelöscht werden.',
            ]);
        }
    }

    public function assertFolderCanBeDeleted(FileFolder $folder): void
    {
        if (isset(self::$forcedFolderDeletes[(int) $folder->id])) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            $this->lockSourceSelection();
        }

        $subtreeIds = $this->folderSubtreeIds($folder);
        $selected = DB::transactionLevel() > 0
            ? $this->lockSourceSelection()
            : $this->selectedFolderId(true);
        if ($selected !== null && $selected > 0 && in_array($selected, $subtreeIds, true)) {
            throw ValidationException::withMessages([
                'folder' => 'Dieser Ordner enthält die aktuell gewählte Marketing-Bildquelle. Bitte zuerst die Bildquelle ändern.',
            ]);
        }

        $fileIds = File::query()->whereIn('folder_id', $subtreeIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $creativeIds = $this->creativeIdsReferencingFileIds($fileIds);
        $this->lockCreativeRows($creativeIds);
        if ($this->creativeIdsReferencingFileIds($fileIds) !== []) {
            throw ValidationException::withMessages([
                'folder' => 'Dieser Ordner enthält Dateien, die noch in Marketing-Motiven verwendet werden.',
            ]);
        }
    }

    public function assertFileCanMoveTo(File $file, FileFolder|int|null $destination): void
    {
        $selected = DB::transactionLevel() > 0
            ? $this->lockSourceSelection()
            : $this->selectedFolderId(true);
        if ($this->creativeIdsReferencingFile($file) === []) {
            return;
        }

        $folderId = $destination instanceof FileFolder ? (int) $destination->id : $destination;
        if ($selected !== null && $selected < 1) {
            throw ValidationException::withMessages(['folder' => 'Die Marketing-Bildquelle ist ungültig.']);
        }

        if (! $this->fileBelongsToCompanyPool($file) || ! $this->folderIdIsInScope($folderId, $selected)) {
            throw ValidationException::withMessages([
                'folder' => 'Die Datei wird in einem Marketing-Motiv verwendet und darf nicht aus der gewählten Bildquelle verschoben werden.',
            ]);
        }
    }

    public function assertFolderCanMoveTo(
        FileFolder $folder,
        ?int $destinationParentId,
        int $destinationPoolId,
    ): void {
        $pool = $this->sourcePool();
        $fromCompanyPool = (int) $folder->getOriginal('file_pool_id') === (int) $pool->id;
        $toCompanyPool = $destinationPoolId === (int) $pool->id;
        $selected = $fromCompanyPool || $toCompanyPool
            ? (DB::transactionLevel() > 0 ? $this->lockSourceSelection() : $this->selectedFolderId(true))
            : null;
        $destinationParent = $destinationParentId !== null
            ? FileFolder::query()->find($destinationParentId)
            : null;

        if ($destinationParentId !== null
            && (! $destinationParent || (int) $destinationParent->file_pool_id !== $destinationPoolId)) {
            throw ValidationException::withMessages([
                'folder' => 'Der Zielordner gehört nicht zum gewählten Dateipool.',
            ]);
        }

        $subtreeIds = $this->folderSubtreeIdsAnyPool($folder);
        if ($destinationParent && in_array((int) $destinationParent->id, $subtreeIds, true)) {
            throw ValidationException::withMessages([
                'folder' => 'Ein Ordner kann nicht in sich selbst oder einen eigenen Unterordner verschoben werden.',
            ]);
        }

        if (! $fromCompanyPool && ! $toCompanyPool) {
            return;
        }

        $selectedMovesWithFolder = $selected !== null
            && $selected > 0
            && in_array($selected, $subtreeIds, true);
        $referencedCreatives = $this->creativeIdsReferencingFileIds(
            File::query()
                ->whereIn('folder_id', $subtreeIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );

        if ((! $toCompanyPool && ($selectedMovesWithFolder || $referencedCreatives !== []))
            || ($selected !== null && $selected < 1 && $referencedCreatives !== [])) {
            throw ValidationException::withMessages([
                'folder' => 'Der Ordner enthält die Marketing-Bildquelle oder verwendete Motivbilder und darf diesen Dateipool nicht verlassen.',
            ]);
        }

        if ($referencedCreatives !== []
            && $selected !== null
            && $selected > 0
            && ! $selectedMovesWithFolder) {
            $destinationInSource = $destinationParent !== null
                && $this->folderIdIsInScope((int) $destinationParent->id, $selected);
            if (! $destinationInSource) {
                throw ValidationException::withMessages([
                    'folder' => 'Der Ordner enthält verwendete Motivbilder und darf nicht aus der gewählten Bildquelle verschoben werden.',
                ]);
            }
        }
    }

    public function handleFolderMutation(FileFolder $folder): void
    {
        if ($folder->isWithinVisibilityWindow() && ! $folder->isExpiredForDeletion()) {
            return;
        }

        $selected = DB::transactionLevel() > 0
            ? $this->lockSourceSelection()
            : $this->selectedFolderId(true);
        $subtreeIds = $this->folderSubtreeIdsAnyPool($folder);
        $selectedAffected = $selected !== null
            && $selected > 0
            && in_array($selected, $subtreeIds, true);
        $referencedCreatives = $this->creativeIdsReferencingFileIds(
            File::query()
                ->whereIn('folder_id', $subtreeIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );

        if ($selectedAffected || $referencedCreatives !== []) {
            throw ValidationException::withMessages([
                'folder' => 'Der Ordner ist Marketing-Bildquelle oder enthält verwendete Motivbilder und muss dafür aktuell sichtbar bleiben.',
            ]);
        }
    }

    public function handleForcedFileDeletion(File $file, ?int $actorId = null): void
    {
        DB::transaction(function () use ($file, $actorId): void {
            $this->lockSourceSelection();
            $creativeIds = $this->creativeIdsReferencingFile($file);
            $this->lockCreativeRows($creativeIds);
            $locked = File::query()->lockForUpdate()->find($file->id);
            if (! $locked) {
                return;
            }

            $creativeIds = $this->creativeIdsReferencingFile($locked);
            $this->invalidateCreatives($creativeIds, $actorId, 'Eine verwendete Datei wurde automatisch gelöscht.');

            self::$forcedFileDeletes[(int) $locked->id] = true;
            try {
                $locked->delete();
            } finally {
                unset(self::$forcedFileDeletes[(int) $locked->id]);
            }
        });
    }

    public function handleForcedFolderDeletion(FileFolder $folder, ?int $actorId = null): void
    {
        DB::transaction(function () use ($folder, $actorId): void {
            $this->lockSourceSelection();
            $folderIds = $this->folderSubtreeIds($folder);
            $lockedFolders = FileFolder::query()
                ->whereIn('id', $folderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $lockedFolders->firstWhere('id', (int) $folder->id);
            if (! $locked) {
                return;
            }

            $fileIds = File::query()
                ->whereIn('folder_id', $folderIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $creativeIds = $this->creativeIdsReferencingFileIds($fileIds);
            $this->lockCreativeRows($creativeIds);
            $files = File::query()->whereIn('id', $fileIds)->orderBy('id')->lockForUpdate()->get();
            $creativeIds = $this->creativeIdsReferencingFileIds($fileIds);
            $this->invalidateCreatives($creativeIds, $actorId, 'Eine verwendete Datei wurde automatisch gelöscht.');

            foreach ($folderIds as $folderId) {
                self::$forcedFolderDeletes[$folderId] = true;
            }
            foreach ($files as $file) {
                self::$forcedFileDeletes[(int) $file->id] = true;
            }

            try {
                $locked->deleteRecursive(false);
            } finally {
                foreach ($folderIds as $folderId) {
                    unset(self::$forcedFolderDeletes[$folderId]);
                }
                foreach ($files as $file) {
                    unset(self::$forcedFileDeletes[(int) $file->id]);
                }
            }
        });
    }

    public function handleFileContentMutation(File $file, ?int $actorId = null): void
    {
        if (DB::transactionLevel() > 0) {
            $this->lockSourceSelection();
        }

        $creativeIds = $this->creativeIdsReferencingFile($file);
        $this->lockCreativeRows($creativeIds);
        $creativeIds = $this->creativeIdsReferencingFile($file);
        $this->invalidateCreatives($creativeIds, $actorId, 'Eine verwendete Datei wurde geändert.');
    }

    /** @return Collection<int, File> */
    private function candidateFiles(): Collection
    {
        $pool = $this->sourcePool();
        $selected = $this->selectedFolderId(true);
        if ($selected !== null && $selected < 1) {
            return collect();
        }

        return File::query()
            ->where('fileable_type', $pool->getMorphClass())
            ->where('fileable_id', $pool->id)
            ->where('disk', 'private')
            ->latest('updated_at')
            ->get()
            ->filter(fn (File $file): bool => $this->fileIsInScope($file, $selected));
    }

    /**
     * @return array{id: int, name: string, contents: string, mime_type: string, size: int, width: int, height: int, sha256: string}
     */
    private function readAndValidate(File $file): array
    {
        $diskName = trim((string) ($file->disk ?: 'private'));
        $path = trim((string) $file->path);
        if ($diskName !== 'private' || $path === '') {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei ist nicht lesbar.']);
        }

        $maxBytes = max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        if ($file->size !== null && ((int) $file->size < 1 || (int) $file->size > $maxBytes)) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei überschreitet die sichere Dateigröße.']);
        }

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                throw new RuntimeException('missing');
            }
            $storageSize = $disk->size($path);
            if ($storageSize < 1 || $storageSize > $maxBytes) {
                throw ValidationException::withMessages(['file' => 'Die Bilddatei überschreitet die sichere Dateigröße.']);
            }

            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException('stream');
            }
            try {
                $contents = stream_get_contents($stream, $maxBytes + 1);
            } finally {
                fclose($stream);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei ist nicht mehr vorhanden oder nicht lesbar.']);
        }

        if (! is_string($contents)) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei ist nicht mehr vorhanden oder nicht lesbar.']);
        }
        $size = strlen($contents);
        if ($size < 1 || $size > $maxBytes || $size !== (int) $storageSize) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei überschreitet die sichere Dateigröße.']);
        }
        if ($file->size !== null && (int) $file->size !== $size) {
            throw ValidationException::withMessages([
                'file' => 'Die gespeicherte Dateigröße stimmt nicht mit dem Bildinhalt überein.',
            ]);
        }

        $dimensions = @getimagesizefromstring($contents);
        $actualMime = is_array($dimensions) ? strtolower((string) ($dimensions['mime'] ?? '')) : '';
        $declaredMime = strtolower(trim((string) $file->mime_type));
        if (! is_array($dimensions)
            || ! isset($dimensions[0], $dimensions[1])
            || ! isset(self::ALLOWED_MIME_TYPES[$actualMime])
            || $declaredMime !== $actualMime) {
            throw ValidationException::withMessages(['file' => 'Erlaubt sind ausschließlich echte JPEG-, PNG-, WebP- und GIF-Bilder.']);
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = max(1, (int) config('marketing.assets.max_pixels', 40_000_000));
        if ($width < 1 || $height < 1 || $width > intdiv($maxPixels, $height)) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei überschreitet die sichere Pixelgrenze.']);
        }

        $sha256 = hash('sha256', $contents);
        if ($file->content_sha256 !== null && ! hash_equals(strtolower((string) $file->content_sha256), $sha256)) {
            throw ValidationException::withMessages(['file' => 'Der Inhalt der Bilddatei stimmt nicht mit der gespeicherten Prüfsumme überein.']);
        }
        if ($file->image_width !== null && (int) $file->image_width !== $width
            || $file->image_height !== null && (int) $file->image_height !== $height) {
            throw ValidationException::withMessages(['file' => 'Die Bildabmessungen stimmen nicht mit den gespeicherten Metadaten überein.']);
        }

        return [
            'id' => (int) $file->id,
            'name' => basename(str_replace(["\r", "\n", '"'], '', (string) ($file->name ?: 'bild'))),
            'contents' => $contents,
            'mime_type' => $actualMime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
        ];
    }

    private function fileBelongsToCompanyPool(File $file): bool
    {
        $pool = $this->sourcePool();

        return (string) $file->fileable_type === (string) $pool->getMorphClass()
            && (int) $file->fileable_id === (int) $pool->id;
    }

    private function folderIdIsInScope(?int $fileFolderId, ?int $selectedFolderId): bool
    {
        if ($fileFolderId === null) {
            return $selectedFolderId === null;
        }

        $pool = $this->sourcePool();
        $folder = FileFolder::query()->find($fileFolderId);
        $chain = $folder ? $this->folderChain($folder, $pool) : null;
        if ($chain === null) {
            return false;
        }

        return $selectedFolderId === null
            || $chain->contains(fn (FileFolder $ancestor): bool => (int) $ancestor->id === $selectedFolderId);
    }

    /** @return Collection<int, FileFolder>|null */
    private function folderChain(FileFolder $folder, FilePool $pool): ?Collection
    {
        $chain = collect();
        $visited = [];
        $current = $folder;

        for ($depth = 0; $depth < self::MAX_FOLDER_DEPTH; $depth++) {
            $id = (int) $current->id;
            if ((int) $current->file_pool_id !== (int) $pool->id
                || isset($visited[$id])
                || ! $current->isWithinVisibilityWindow()
                || $current->isExpiredForDeletion()) {
                return null;
            }

            $visited[$id] = true;
            $chain->prepend($current);

            if ($current->parent_id === null) {
                return $chain;
            }

            $parent = FileFolder::query()->find($current->parent_id);
            if (! $parent) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    private function folderPath(?FileFolder $folder): string
    {
        if (! $folder) {
            return '';
        }

        return $this->folderChain($folder, $this->sourcePool())?->pluck('name')->implode(' / ') ?? '';
    }

    /** @return list<int> */
    private function folderSubtreeIds(FileFolder $folder): array
    {
        $pool = $this->sourcePool();
        if ((int) $folder->file_pool_id !== (int) $pool->id) {
            return [(int) $folder->id];
        }

        $children = FileFolder::query()
            ->where('file_pool_id', $pool->id)
            ->get(['id', 'parent_id'])
            ->groupBy(fn (FileFolder $item): int => (int) ($item->parent_id ?? 0));
        $queue = [(int) $folder->id];
        $visited = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return array_keys($visited);
    }

    /** @return list<int> */
    private function folderSubtreeIdsAnyPool(FileFolder $folder): array
    {
        $children = FileFolder::query()
            ->where('file_pool_id', $folder->getOriginal('file_pool_id'))
            ->get(['id', 'parent_id'])
            ->groupBy(fn (FileFolder $item): int => (int) ($item->parent_id ?? 0));
        $queue = [(int) $folder->id];
        $visited = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return array_keys($visited);
    }

    private function assertAllReferencesAllowedForSource(?int $folderId, bool $lockRows = false): void
    {
        $fileIds = $this->allReferencedFileIds($lockRows);
        $files = $lockRows
            ? $this->lockFilesForUpdate($fileIds)->keyBy(fn (File $file): int => (int) $file->id)
            : File::query()->whereIn('id', $fileIds)->get()->keyBy(fn (File $file): int => (int) $file->id);

        foreach ($fileIds as $fileId) {
            $file = $files->get($fileId);
            if (! $file || ! $this->fileIsInScope($file, $folderId)) {
                throw ValidationException::withMessages([
                    'mediaFolderId' => 'Der gewählte Ordner würde mindestens ein bereits verwendetes Motivbild ausschließen.',
                ]);
            }

            $this->readAndValidate($file);
        }
    }

    /** @return list<int> */
    private function allReferencedFileIds(bool $lockRows = false): array
    {
        $ids = [];
        $creatives = MarketingCreative::query()->select(['id', 'shared_content'])->orderBy('id');
        if ($lockRows) {
            $creatives->lockForUpdate();
        }
        $creatives->each(function (MarketingCreative $creative) use (&$ids): void {
            $json = json_encode($creative->shared_content ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            foreach ($this->referencedFileIds($json) as $id) {
                $ids[$id] = true;
            }
        });

        $variants = MarketingCreativeVariant::query()
            ->whereHas('creative')
            ->select(['id', 'html', 'css', 'builder_data'])
            ->orderBy('id');
        if ($lockRows) {
            $variants->lockForUpdate();
        }
        $variants->each(function (MarketingCreativeVariant $variant) use (&$ids): void {
            $json = json_encode($variant->builder_data ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            foreach ($this->referencedFileIds($variant->html, $variant->css, $json) as $id) {
                $ids[$id] = true;
            }
        });
        $result = array_keys($ids);
        sort($result);

        return $result;
    }

    /** @return list<int> */
    private function creativeIdsReferencingFile(File $file): array
    {
        return $this->creativeIdsReferencingFileIds([(int) $file->id]);
    }

    /** @param list<int> $creativeIds */
    private function lockCreativeRows(array $creativeIds): void
    {
        if ($creativeIds === [] || DB::transactionLevel() < 1) {
            return;
        }

        sort($creativeIds);
        MarketingCreative::query()
            ->whereIn('id', $creativeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        MarketingCreativeVariant::query()
            ->whereIn('marketing_creative_id', $creativeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param list<int> $fileIds
     * @return list<int>
     */
    private function creativeIdsReferencingFileIds(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $wanted = array_fill_keys($fileIds, true);
        $creativeIds = [];
        MarketingCreativeVariant::query()
            ->whereHas('creative')
            ->select(['marketing_creative_id', 'html', 'css', 'builder_data'])
            ->each(function (MarketingCreativeVariant $variant) use ($wanted, &$creativeIds): void {
                $json = json_encode($variant->builder_data ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                foreach ($this->referencedFileIds($variant->html, $variant->css, $json) as $id) {
                    if (isset($wanted[$id])) {
                        $creativeIds[(int) $variant->marketing_creative_id] = true;
                    }
                }
            });
        MarketingCreative::query()->select(['id', 'shared_content'])
            ->each(function (MarketingCreative $creative) use ($wanted, &$creativeIds): void {
                $json = json_encode($creative->shared_content ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                foreach ($this->referencedFileIds($json) as $id) {
                    if (isset($wanted[$id])) {
                        $creativeIds[(int) $creative->id] = true;
                    }
                }
            });

        return array_keys($creativeIds);
    }

    /** @param list<int> $creativeIds */
    private function invalidateCreatives(array $creativeIds, ?int $actorId, string $reason): void
    {
        if ($creativeIds === []) {
            return;
        }

        MarketingCreative::query()
            ->whereIn('id', $creativeIds)
            ->where('status', MarketingCreativeStatus::Approved->value)
            ->lockForUpdate()
            ->get()
            ->each(function (MarketingCreative $creative) use ($actorId): void {
                $creative->forceFill([
                    'status' => MarketingCreativeStatus::Draft->value,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_dependency_hash' => null,
                    'updated_by' => $actorId,
                ])->save();
            });

        $renders = MarketingRender::query()
            ->whereIn('marketing_creative_id', $creativeIds)
            ->whereIn('status', [
                MarketingRenderStatus::Pending->value,
                MarketingRenderStatus::Processing->value,
                MarketingRenderStatus::Completed->value,
            ])
            ->lockForUpdate()
            ->get();
        foreach ($renders as $render) {
            $disk = $render->disk;
            $path = $render->path;
            $render->forceFill([
                'status' => MarketingRenderStatus::Failed->value,
                'path' => null,
                'mime_type' => null,
                'error' => $reason,
                'rendered_at' => null,
            ])->save();
            if ($path && $this->isPrivateDisk((string) $disk)) {
                DB::afterCommit(static function () use ($disk, $path): void {
                    try {
                        Storage::disk($disk)->delete($path);
                    } catch (Throwable $exception) {
                        Log::warning('Konnte veralteten Marketing-Export nicht entfernen.', [
                            'disk' => $disk,
                            'path' => $path,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });
            }
        }
    }

    private function isPrivateDisk(string $disk): bool
    {
        return $disk !== '' && config('filesystems.disks.'.$disk.'.visibility') === 'private';
    }
}
