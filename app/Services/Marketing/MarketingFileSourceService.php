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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class MarketingFileSourceService
{
    public const FILE_PATTERN = '#(?:https?://[^\s"\')]+)?/administrator/marketing/dateien/([1-9][0-9]*)(?:\?v=[a-f0-9]{8,64})?#i';

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

    public function setSelectedFolder(?int $folderId, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);

        $pool = $this->sourcePool();
        if ($folderId !== null) {
            $folder = FileFolder::query()->find($folderId);
            if (! $folder || $this->folderChain($folder, $pool) === null) {
                throw ValidationException::withMessages([
                    'mediaFolderId' => 'Der gewählte Dateiordner gehört nicht zum Firmen-Dateipool oder ist nicht mehr verfügbar.',
                ]);
            }
        }

        $this->assertAllReferencesAllowedForSource($folderId);

        DB::transaction(function () use ($folderId): void {
            $setting = Setting::query()
                ->where('type', MarketingFileSourceSettings::GROUP)
                ->where('key', MarketingFileSourceSettings::KEY)
                ->lockForUpdate()
                ->first();

            $current = MarketingFileSourceSettings::selectedFolderId(true);
            if ($current === $folderId) {
                return;
            }

            if ($setting) {
                $setting->forceFill(['value' => ['selected_folder_id' => $folderId]])->save();
            } else {
                Setting::query()->create([
                    'type' => MarketingFileSourceSettings::GROUP,
                    'key' => MarketingFileSourceSettings::KEY,
                    'value' => ['selected_folder_id' => $folderId],
                ]);
            }
        });

        Cache::forget('settings.'.MarketingFileSourceSettings::GROUP.'.'.MarketingFileSourceSettings::KEY);
    }

    /** @return list<array{src: string, name: string, type: string, category: string, width: int, height: int}> */
    public function editorAssets(): array
    {
        if ($this->hasInvalidSelection()) {
            return [];
        }

        return $this->candidateFiles()
            ->map(function (File $file): ?array {
                try {
                    $snapshot = $this->validatedSnapshot($file);
                } catch (Throwable) {
                    return null;
                }

                return [
                    'src' => route('admin.marketing.files.show', $file).'?v='.substr($snapshot['sha256'], 0, 16),
                    'name' => $snapshot['name'],
                    'type' => 'image',
                    'category' => $this->folderPath($file->folder) ?: 'Grundverzeichnis',
                    'width' => $snapshot['width'],
                    'height' => $snapshot['height'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function editorAssetCount(): int
    {
        return count($this->editorAssets());
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
            || $file->legacy_marketing_asset_deleted_at !== null
            || ! $file->isWithinVisibilityWindow()
            || $file->isExpiredForDeletion()) {
            return false;
        }

        return $this->folderIdIsInScope($file->folder_id ? (int) $file->folder_id : null, $folderId);
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
                || isset($candidate['fragment'])) {
                return null;
            }

            $path = (string) ($candidate['path'] ?? '');
            $query = (string) ($candidate['query'] ?? '');
        } else {
            $parts = parse_url($url);
            if (! is_array($parts) || isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
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

        $subtreeIds = $this->folderSubtreeIds($folder);
        $selected = $this->selectedFolderId(true);
        if ($selected !== null && $selected > 0 && in_array($selected, $subtreeIds, true)) {
            throw ValidationException::withMessages([
                'folder' => 'Dieser Ordner enthält die aktuell gewählte Marketing-Bildquelle. Bitte zuerst die Bildquelle ändern.',
            ]);
        }

        $fileIds = File::query()->whereIn('folder_id', $subtreeIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($this->creativeIdsReferencingFileIds($fileIds) !== []) {
            throw ValidationException::withMessages([
                'folder' => 'Dieser Ordner enthält Dateien, die noch in Marketing-Motiven verwendet werden.',
            ]);
        }
    }

    public function assertFileCanMoveTo(File $file, FileFolder|int|null $destination): void
    {
        if ($this->creativeIdsReferencingFile($file) === []) {
            return;
        }

        $folderId = $destination instanceof FileFolder ? (int) $destination->id : $destination;
        $selected = $this->selectedFolderId(true);
        if ($selected !== null && $selected < 1) {
            throw ValidationException::withMessages(['folder' => 'Die Marketing-Bildquelle ist ungültig.']);
        }

        if (! $this->fileBelongsToCompanyPool($file) || ! $this->folderIdIsInScope($folderId, $selected)) {
            throw ValidationException::withMessages([
                'folder' => 'Die Datei wird in einem Marketing-Motiv verwendet und darf nicht aus der gewählten Bildquelle verschoben werden.',
            ]);
        }
    }

    public function handleForcedFileDeletion(File $file, ?int $actorId = null): void
    {
        DB::transaction(function () use ($file, $actorId): void {
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
            $locked = FileFolder::query()->lockForUpdate()->find($folder->id);
            if (! $locked) {
                return;
            }

            $folderIds = $this->folderSubtreeIds($locked);
            $files = File::query()->whereIn('folder_id', $folderIds)->lockForUpdate()->get();
            $creativeIds = $this->creativeIdsReferencingFileIds($files->pluck('id')->map(fn ($id): int => (int) $id)->all());
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
            ->whereNull('legacy_marketing_asset_deleted_at')
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
        if ($diskName === '' || $path === '') {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei ist nicht lesbar.']);
        }

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                throw new RuntimeException('missing');
            }
            $contents = $disk->get($path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei ist nicht mehr vorhanden oder nicht lesbar.']);
        }

        $size = strlen($contents);
        $maxBytes = max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        if ($size < 1 || $size > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei überschreitet die sichere Dateigröße.']);
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

    private function assertAllReferencesAllowedForSource(?int $folderId): void
    {
        foreach ($this->allReferencedFileIds() as $fileId) {
            $file = File::query()->find($fileId);
            if (! $file || ! $this->fileIsInScope($file, $folderId)) {
                throw ValidationException::withMessages([
                    'mediaFolderId' => 'Der gewählte Ordner würde mindestens ein bereits verwendetes Motivbild ausschließen.',
                ]);
            }

            $this->readAndValidate($file);
        }
    }

    /** @return list<int> */
    private function allReferencedFileIds(): array
    {
        $ids = [];
        MarketingCreativeVariant::query()->select(['html', 'css', 'builder_data'])->each(function (MarketingCreativeVariant $variant) use (&$ids): void {
            $json = json_encode($variant->builder_data ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            foreach ($this->referencedFileIds($variant->html, $variant->css, $json) as $id) {
                $ids[$id] = true;
            }
        });
        MarketingCreative::query()->select(['shared_content'])->each(function (MarketingCreative $creative) use (&$ids): void {
            $json = json_encode($creative->shared_content ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            foreach ($this->referencedFileIds($json) as $id) {
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

    /** @param list<int> $fileIds
     *  @return list<int>
     */
    private function creativeIdsReferencingFileIds(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $wanted = array_fill_keys($fileIds, true);
        $creativeIds = [];
        MarketingCreativeVariant::query()->select(['marketing_creative_id', 'html', 'css', 'builder_data'])
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
            if ($path) {
                DB::afterCommit(static function () use ($disk, $path): void {
                    Storage::disk($disk)->delete($path);
                });
            }
        }
    }
}
