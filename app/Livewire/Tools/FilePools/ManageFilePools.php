<?php

namespace App\Livewire\Tools\FilePools;

use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ManageFilePools extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public ?string $modelType = null;

    #[Locked]
    public ?int $modelId = null;

    #[Locked]
    public ?int $filePoolId = null;

    #[Locked]
    public ?FilePool $filePool = null;

    public array $fileUploads = [];

    public array $selectedFiles = [];

    public array $expires = [];

    public ?File $file = null;

    public string $selectedFileName;

    public string $selectedFileExpiresDate;

    /** Datei-Sichtbarkeit (Zeitfenster ab / Auto-Loeschung / Teams) */
    public string $selectedFileVisibleFrom = '';

    public bool $selectedFileAutoDelete = false;

    public array $selectedFileVisibleTeams = [];

    /** Upload-Sichtbarkeit (Zeitfenster ab / Auto-Loeschung / Teams) */
    public string $uploadVisibleFrom = '';

    public bool $uploadAutoDelete = false;

    public array $uploadVisibleTeams = [];

    public bool $openFileForm = false;

    public bool $openEditFileForm = false;

    #[Locked]
    public bool $readOnly = true;

    /** Team-Berechtigungen fuer die zentrale Dateiverwaltung aktivieren. */
    #[Locked]
    public bool $allowTeamPermissions = false;

    /** Explorer: aktuell geoeffneter Ordner (null = Wurzel) */
    #[Locked]
    public ?int $currentFolderId = null;

    /** Ordner anlegen/umbenennen */
    public bool $openFolderForm = false;

    public ?int $editFolderId = null;

    public string $folderName = '';

    /** Ordner-Sichtbarkeit (Zeitfenster / Auto-Loeschung / Teams) */
    public string $folderVisibleFrom = '';

    public string $folderVisibleUntil = '';

    public bool $folderAutoDelete = false;

    public array $folderVisibleTeams = [];

    /** Ordner-Rechte (Team-ID => Aktion => bool) */
    public bool $openFolderPermissions = false;

    public ?int $permissionsFolderId = null;

    public array $folderPermissions = [];

    public function mount(
        ?string $modelType = null,
        ?int $modelId = null,
        bool $readOnly = true,
        ?int $poolId = null,
        bool $allowTeamPermissions = false,
    ): void {
        if ($modelType === Team::class && $modelId !== null) {
            abort_unless(Auth::user()?->belongsToTeam(Team::findOrFail($modelId)), 403);
        }

        if ($poolId !== null) {
            $this->filePool = FilePool::findOrFail($poolId);
        } else {
            $this->modelType = $modelType;
            $this->modelId = $modelId;
            $model = $modelType::findOrFail($modelId);
            $this->filePool = $model->filePool()->firstOrCreate([
                'title' => 'Standard Ordner',
                'type' => $modelType,
                'description' => '',
            ]);
        }

        $this->filePoolId = $this->filePool->id;
        $this->fileUploads = [$this->filePool->id => []];

        $this->openFileForm = false;
        $this->openEditFileForm = false;
        $this->readOnly = $readOnly;
        $this->allowTeamPermissions = $allowTeamPermissions;

        abort_unless($this->userCanAccessPool($this->filePool), 403);
    }

    /* ------------------------------------------------------------------
     * Explorer: Navigation & Ordnerverwaltung
     * ----------------------------------------------------------------*/

    /**
     * In einen Ordner wechseln (null = Wurzel). Prueft Pool-Zugehoerigkeit
     * und fuer normale Benutzer die Team-Berechtigung des Ordners.
     */
    public function enterFolder(?int $folderId = null): void
    {
        if ($folderId === null) {
            $this->currentFolderId = null;

            return;
        }

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);

        $this->authorizeFolderView($folder);

        $this->currentFolderId = $folder->id;
    }

    public function openCreateFolder(): void
    {
        abort_if($this->readOnly, 403);

        $this->reset([
            'editFolderId', 'folderName',
            'folderVisibleFrom', 'folderVisibleUntil', 'folderAutoDelete', 'folderVisibleTeams',
        ]);
        $this->resetValidation();
        $this->openFolderForm = true;
    }

    public function openRenameFolder(int $folderId): void
    {
        abort_if($this->readOnly, 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);
        $this->editFolderId = $folder->id;
        $this->folderName = $folder->name;
        $this->folderVisibleFrom = $folder->visible_from?->format('Y-m-d') ?? '';
        $this->folderVisibleUntil = $folder->visible_until?->format('Y-m-d') ?? '';
        $this->folderAutoDelete = (bool) $folder->auto_delete;
        $this->folderVisibleTeams = array_map('intval', (array) ($folder->visible_teams ?? []));
        $this->resetValidation();
        $this->openFolderForm = true;
    }

    public function saveFolder(): void
    {
        abort_if($this->readOnly, 403);

        $this->validate([
            'folderName' => ['required', 'string', 'max:255'],
            'folderVisibleFrom' => ['nullable', 'date'],
            'folderVisibleUntil' => ['nullable', 'date', 'after_or_equal:folderVisibleFrom'],
        ]);

        $payload = [
            'name' => $this->folderName,
            'visible_from' => $this->folderVisibleFrom ?: null,
            'visible_until' => $this->folderVisibleUntil ?: null,
            'auto_delete' => (bool) $this->folderAutoDelete,
            'visible_teams' => array_values(array_intersect(
                array_map('intval', $this->folderVisibleTeams),
                Team::where('personal_team', false)->pluck('id')->all()
            )),
        ];

        if ($this->editFolderId) {
            FileFolder::where('file_pool_id', $this->filePoolId)
                ->findOrFail($this->editFolderId)
                ->update($payload);
        } else {
            FileFolder::create(array_merge($payload, [
                'file_pool_id' => $this->filePoolId,
                'parent_id' => $this->currentFolderId,
            ]));
        }

        $this->reset([
            'editFolderId', 'folderName', 'openFolderForm',
            'folderVisibleFrom', 'folderVisibleUntil', 'folderAutoDelete', 'folderVisibleTeams',
        ]);
        $this->dispatch('swal:toast', type: 'success', text: __('app.folder_saved'));
    }

    public function deleteFolder(int $folderId): void
    {
        abort_if($this->readOnly, 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);
        $folder->deleteRecursive();

        if ($this->currentFolderId === $folderId) {
            $this->currentFolderId = $folder->parent_id;
        }

        $this->dispatch('swal:toast', type: 'success', text: __('app.folder_deleted'));
    }

    public function openPermissions(int $folderId): void
    {
        abort_if($this->readOnly || ! $this->allowTeamPermissions, 403);
        abort_unless($this->canManageCompanyPermissions(), 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);
        $this->permissionsFolderId = $folder->id;

        $stored = is_array($folder->permissions) ? $folder->permissions : [];
        $this->folderPermissions = [];

        foreach ($this->availableTeamIds() as $teamId) {
            foreach (array_keys(FileFolder::permissionActions()) as $action) {
                $this->folderPermissions[$teamId][$action] = (bool) ($stored[$teamId][$action] ?? false);
            }
        }

        $this->openFolderPermissions = true;
    }

    public function savePermissions(): void
    {
        abort_if($this->readOnly || ! $this->allowTeamPermissions, 403);
        abort_unless($this->canManageCompanyPermissions(), 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($this->permissionsFolderId);

        $payload = [];
        foreach ($this->availableTeamIds() as $teamId) {
            foreach (array_keys(FileFolder::permissionActions()) as $action) {
                $payload[$teamId][$action] = (bool) ($this->folderPermissions[$teamId][$action] ?? false);
            }
        }

        $folder->update(['permissions' => $payload]);

        $this->reset(['permissionsFolderId', 'folderPermissions', 'openFolderPermissions']);
        $this->dispatch('swal:toast', type: 'success', text: __('app.folder_permissions_saved'));
    }

    public function uploadFile(int $filePoolId)
    {
        abort_if($this->readOnly, 403);

        $this->validate([
            "fileUploads.$filePoolId" => ['required', 'array', 'min:1'],
            "fileUploads.$filePoolId.*" => ['file', 'max:302400'], // 300 MB je Datei
            "expires.$filePoolId" => ['nullable', 'date', 'after:today'],
        ]);

        foreach ($this->fileUploads[$filePoolId] as $uploadedFile) {
            $filename = $uploadedFile->getClientOriginalName();
            $path = $uploadedFile->store('uploads/files', 'private');
            $mime = Storage::disk('private')->mimeType($path) ?? $uploadedFile->getClientMimeType();

            $this->filePool->files()->create([
                'folder_id' => $this->currentFolderId,
                'user_id' => Auth::user()->id ?? null,
                'name' => $filename,
                'path' => $path,
                'mime_type' => $mime,
                'size' => $uploadedFile->getSize(),
                'expires_at' => $this->expires[$filePoolId] ?? null,
                'visible_from' => $this->uploadVisibleFrom ?: null,
                'auto_delete' => (bool) $this->uploadAutoDelete,
                'visible_teams' => array_values(array_intersect(
                    array_map('intval', $this->uploadVisibleTeams),
                    Team::where('personal_team', false)->pluck('id')->all()
                )),
            ]);
        }
        unset($this->fileUploads[$filePoolId], $this->expires[$filePoolId]);
        $this->reset(['uploadVisibleFrom', 'uploadAutoDelete', 'uploadVisibleTeams']);
        $this->openFileForm = false;
        $this->filePool->refresh();
        $this->resetErrorBag();

        // Dropzone-Reset anstossen (model-Pfad mitgeben!)
        $this->dispatch('filepool:saved', model: "fileUploads.$filePoolId");
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_uploaded'));
    }

    public function downloadFile(int $fileId): StreamedResponse
    {
        $file = $this->findFileInPoolOrFail($fileId);
        $this->authorizeFileAccess($file, 'download');

        return $file->download(); // zentral im Model
    }

    public function editFile($id)
    {
        abort_if($this->readOnly, 403);

        $this->file = $this->findFileInPoolOrFail((int) $id);
        $this->authorizeFileMutation($this->file);
        $this->selectedFileName = $this->file->name;
        $this->selectedFileExpiresDate = $this->file->expires_at?->format('Y-m-d') ?? '';
        $this->selectedFileVisibleFrom = $this->file->visible_from?->format('Y-m-d') ?? '';
        $this->selectedFileAutoDelete = (bool) $this->file->auto_delete;
        $this->selectedFileVisibleTeams = array_map('intval', (array) ($this->file->visible_teams ?? []));
        $this->openEditFileForm = true;
    }

    public function safeFile()
    {
        abort_if($this->readOnly, 403);

        $this->validate([
            'selectedFileName' => 'required|string|max:255',
            'selectedFileExpiresDate' => 'nullable|date|after_or_equal:today',
            'selectedFileVisibleFrom' => ['nullable', 'date'],
        ]);

        if (! $this->file?->id) {
            $this->addError('file', __('app.no_file_selected'));

            return;
        }

        $file = $this->findFileInPoolOrFail($this->file->id);
        $this->authorizeFileMutation($file);

        $payload = [
            'name' => $this->selectedFileName,
            'expires_at' => $this->selectedFileExpiresDate ?: null,
            'visible_from' => $this->selectedFileVisibleFrom ?: null,
            'auto_delete' => (bool) $this->selectedFileAutoDelete,
            'visible_teams' => array_values(array_intersect(
                array_map('intval', $this->selectedFileVisibleTeams),
                Team::where('personal_team', false)->pluck('id')->all()
            )),
        ];

        if ($this->allowTeamPermissions) {
            // Rollenfreigaben sind im Datei-Explorer abgeloest. Beim naechsten
            // Speichern wird ein alter Wert entfernt, damit nur Teams wirken.
            $payload['shared_roles'] = null;
        }

        $file->update($payload);

        $this->reset([
            'file', 'selectedFileName', 'selectedFileExpiresDate', 'openEditFileForm',
            'selectedFileVisibleFrom', 'selectedFileAutoDelete', 'selectedFileVisibleTeams',
        ]);
        $this->filePool->refresh();
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_saved'));
    }

    /**
     * Gemeinsamer Helper: erzeugt ein ZIP unter storage_path("app/private/zips")
     * und liefert eine BinaryFileResponse, die die ZIP-Datei nach dem Senden loescht.
     *
     * @param  string  $baseName  (ohne .zip)
     * @param  Collection|\Traversable|array  $files  Sammlung von File-Modellen
     * @return BinaryFileResponse
     */
    protected function buildZipResponse(string $baseName, $files)
    {
        $zipFileName = trim($baseName).'.zip';
        $zipDir = storage_path('app/private/zips');
        $zipPath = $zipDir.DIRECTORY_SEPARATOR.$zipFileName;

        if (! is_dir($zipDir)) {
            mkdir($zipDir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'ZIP konnte nicht erzeugt werden.');
        }

        $countAdded = 0;

        foreach ($files as $file) {
            // Datei ggf. ueberspringen, wenn abgelaufen
            if ($file->expires_at && now()->isAfter($file->expires_at)) {
                continue;
            }

            // Wir speichern auf 'private' -> also von dort lesen
            $absolutePath = Storage::disk('private')->path($file->path);
            if (is_file($absolutePath) && is_readable($absolutePath)) {
                // Im Archiv mit Originalnamen ablegen
                $zip->addFile($absolutePath, $file->name_with_extension);
                $countAdded++;
            }
        }

        $zip->close();

        if ($countAdded === 0) {
            // ZIP wieder loeschen, wenn leer
            @unlink($zipPath);
            abort(404, 'Keine (nicht abgelaufenen) Dateien gefunden.');
        }

        // Download-Response mit Auto-Loeschung
        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Laedt die Dateien des aktuellen Pools als ZIP (nutzt die 'private' Disk,
     * abgelaufene Dateien werden ignoriert).
     */
    public function downloadFiles()
    {
        $files = $this->filePool
            ? $this->visibleFiles()
            : collect();

        $base = 'RailTime_Dateien_'.now()->format('Ymd_His');

        return $this->buildZipResponse($base, $files);
    }

    /**
     * Dateien der aktuellen Explorer-Ebene unter Beruecksichtigung der
     * Team-Sichtbarkeit und Team-Rechte.
     */
    protected function visibleFiles()
    {
        $files = $this->filePool->files()
            ->where(fn ($q) => $this->currentFolderId === null
                ? $q->whereNull('folder_id')
                : $q->where('folder_id', $this->currentFolderId))
            ->latest()
            ->get();

        if (! $this->canBypassFileVisibility()) {
            $files = $files->filter(
                fn (File $file) => Auth::user()?->canAccessFile($file, 'download') === true
            )->values();
        }

        return $files;
    }

    /**
     * Laedt ALLE nicht abgelaufenen Dateien des aktuellen Pools als ZIP.
     */
    public function downloadAll()
    {
        if (! $this->filePool) {
            abort(404, 'FilePool nicht gefunden.');
        }

        $files = $this->visibleFiles();

        $base = 'RailTime_Dateien_'.now()->format('Y_m_d-H_i_s');

        return $this->buildZipResponse($base, $files);
    }

    public function deleteFile(int $fileId)
    {
        abort_if($this->readOnly, 403);

        $file = $this->findFileInPoolOrFail($fileId);
        $this->authorizeFileMutation($file);
        $file->delete();
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_deleted'));
    }

    /**
     * Verschiebt eine Datei innerhalb des aktuell sichtbaren Explorer-Kontexts.
     *
     * Als Ziele sind nur direkte, sichtbare Unterordner der aktuellen Ebene,
     * ein Ordner im aktuellen Breadcrumb oder die Wurzel erlaubt. Dadurch
     * kann ein manipulierter Livewire-Aufruf weder einen fremden Pool noch
     * einen versteckten Ordner als Ziel verwenden.
     */
    public function moveFile(int $fileId, ?int $targetFolderId = null): void
    {
        abort_if($this->readOnly, 403);

        $pool = FilePool::findOrFail($this->filePoolId);
        $file = File::findOrFail($fileId);

        abort_unless(
            $file->fileable_type === FilePool::class
                && (int) $file->fileable_id === (int) $pool->id,
            403
        );

        $this->authorizeFileMove($pool, $file);

        $targetFolder = null;

        if ($targetFolderId !== null) {
            $targetFolder = FileFolder::findOrFail($targetFolderId);

            abort_unless((int) $targetFolder->file_pool_id === (int) $pool->id, 403);
            abort_unless($this->isAvailableDropTarget($targetFolder), 403);

            $this->authorizeTargetFolder($targetFolder);
        }

        if ((int) ($file->folder_id ?? 0) === (int) ($targetFolder?->id ?? 0)) {
            $this->dispatch('swal:toast', type: 'info', text: __('app.file_already_in_folder'));

            return;
        }

        DB::transaction(function () use ($file, $targetFolder): void {
            $file->update(['folder_id' => $targetFolder?->id]);
        });

        $this->filePool = $pool->fresh();
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_moved'));
    }

    protected function authorizeFileMove(FilePool $pool, File $file): void
    {
        $user = Auth::user();

        abort_unless($user, 403);

        if ($user->isAdmin() || Gate::allows('files.manage')) {
            return;
        }

        if ($pool->filepoolable_type === User::class) {
            $managesUsers = Gate::allows('users.edit');
            $ownsPoolAndFile = (int) $pool->filepoolable_id === (int) $user->id
                && (int) $file->user_id === (int) $user->id;

            abort_unless($managesUsers || $ownsPoolAndFile, 403);

            return;
        }

        if ($pool->filepoolable_type === Team::class) {
            $team = Team::find($pool->filepoolable_id);

            abort_unless(
                $team
                    && $user->belongsToTeam($team)
                    && (int) $file->user_id === (int) $user->id,
                403
            );

            $this->authorizeSourceFolder($file);

            return;
        }

        abort(403);
    }

    protected function authorizeFileMutation(File $file): void
    {
        $pool = FilePool::findOrFail($this->filePoolId);

        $this->authorizeFileMove($pool, $file);
    }

    protected function authorizeSourceFolder(File $file): void
    {
        if (! $file->folder_id) {
            return;
        }

        $sourceFolder = FileFolder::where('file_pool_id', $this->filePoolId)
            ->findOrFail($file->folder_id);
        abort_unless(
            $sourceFolder->allowsForUser(Auth::user(), 'delete')
                && $sourceFolder->isPubliclyVisible(Auth::user()),
            403
        );
    }

    protected function authorizeTargetFolder(FileFolder $folder): void
    {
        $user = Auth::user();

        if ($user?->isAdmin() || Gate::allows('files.manage') || Gate::allows('users.edit')) {
            return;
        }

        abort_unless(
            $folder->allowsForUser($user, 'view')
                && $folder->allowsForUser($user, 'delete')
                && $folder->isPubliclyVisible($user),
            403
        );
    }

    protected function isAvailableDropTarget(FileFolder $targetFolder): bool
    {
        if ((int) ($targetFolder->parent_id ?? 0) === (int) ($this->currentFolderId ?? 0)) {
            return true;
        }

        if (! $this->currentFolderId) {
            return false;
        }

        $currentFolder = FileFolder::where('file_pool_id', $this->filePoolId)
            ->findOrFail($this->currentFolderId);

        return collect($currentFolder->breadcrumb())
            ->contains(fn (FileFolder $folder): bool => (int) $folder->id === (int) $targetFolder->id);
    }

    protected function findFileInPoolOrFail(int $fileId): File
    {
        return File::query()
            ->where('fileable_type', (new FilePool)->getMorphClass())
            ->where('fileable_id', $this->filePoolId)
            ->findOrFail($fileId);
    }

    protected function authorizeFileAccess(File $file, string $action): void
    {
        if ($this->canBypassFileVisibility()) {
            return;
        }

        abort_unless(Auth::user()?->canAccessFile($file, $action) === true, 403);
    }

    protected function authorizeFolderView(FileFolder $folder): void
    {
        if ($this->canBypassFileVisibility()) {
            return;
        }

        $user = Auth::user();

        abort_unless(
            $user
                && $this->userCanAccessPool($this->filePool)
                && $folder->allowsForUser($user, 'view')
                && $folder->isPubliclyVisible($user),
            403
        );
    }

    protected function userCanAccessPool(?FilePool $pool): bool
    {
        $user = Auth::user();

        if (! $pool || ! $user) {
            return false;
        }

        if ($this->canBypassFileVisibility()) {
            return true;
        }

        if ($pool->filepoolable_type === 'company') {
            return true;
        }

        if ($pool->filepoolable_type === User::class) {
            return (int) $pool->filepoolable_id === (int) $user->id;
        }

        if ($pool->filepoolable_type === Team::class) {
            $team = Team::find($pool->filepoolable_id);

            return $team && $user->belongsToTeam($team);
        }

        return false;
    }

    protected function canBypassFileVisibility(): bool
    {
        $user = Auth::user();

        return (bool) ($user && (
            $user->isAdmin()
            || Gate::allows('files.manage')
            || Gate::allows('users.edit')
        ));
    }

    protected function canManageCompanyPermissions(): bool
    {
        $user = Auth::user();

        return (bool) ($user && ($user->isAdmin() || Gate::allows('files.manage')));
    }

    /**
     * @return array<int, int>
     */
    protected function availableTeamIds(): array
    {
        return Team::query()
            ->where('personal_team', false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    protected function canMoveFiles(FilePool $pool): bool
    {
        $user = Auth::user();

        if ($this->readOnly || ! $user) {
            return false;
        }

        if ($user->isAdmin() || Gate::allows('files.manage')) {
            return true;
        }

        if ($pool->filepoolable_type === User::class) {
            return Gate::allows('users.edit')
                || (int) $pool->filepoolable_id === (int) $user->id;
        }

        if ($pool->filepoolable_type === Team::class) {
            $team = Team::find($pool->filepoolable_id);

            return $team && $user->belongsToTeam($team);
        }

        return false;
    }

    public function placeholder()
    {
        $loading = e(__('app.loading'));

        return <<<HTML
            <div role="status" class="h-32 w-full relative animate-pulse">
                    <div class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/70 dark:bg-slate-900/70 transition-opacity">
                        <div class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-2 shadow">
                            <span class="loader"></span>
                            <span class="text-sm text-gray-700 dark:text-slate-300">{$loading}</span>
                        </div>
                    </div>
            </div>
        HTML;
    }

    public function render()
    {
        $filePool = FilePool::find($this->filePoolId);

        $currentFolder = $this->currentFolderId
            ? FileFolder::where('file_pool_id', $this->filePoolId)->find($this->currentFolderId)
            : null;

        // Zustand heilen, falls der Ordner inzwischen geloescht wurde
        if ($this->currentFolderId && ! $currentFolder) {
            $this->currentFolderId = null;
        }

        // Unterordner der aktuellen Ebene (für normale Benutzer nur erlaubte)
        $folders = $filePool
            ? FileFolder::where('file_pool_id', $this->filePoolId)
                ->where(fn ($q) => $this->currentFolderId === null
                    ? $q->whereNull('parent_id')
                    : $q->where('parent_id', $this->currentFolderId))
                ->orderBy('name')
                ->get()
            : collect();

        if (! $this->canBypassFileVisibility()) {
            $folders = $folders->filter(
                fn (FileFolder $folder) => $folder->allowsForUser(Auth::user(), 'view')
                    && $folder->isPubliclyVisible(Auth::user())
            )->values();
        }

        // Dateien der aktuellen Ebene nach Team-Sichtbarkeit/-Berechtigung.
        $poolFiles = $filePool
            ? $filePool->files()
                ->where(fn ($q) => $this->currentFolderId === null
                    ? $q->whereNull('folder_id')
                    : $q->where('folder_id', $this->currentFolderId))
                ->latest()
                ->get()
            : collect();

        if (! $this->canBypassFileVisibility()) {
            $poolFiles = $poolFiles->filter(
                fn (File $file) => Auth::user()?->canAccessFile($file, 'view') === true
            )->values();
        }

        return view('livewire.tools.file-pools.manage-file-pools', [
            'filePool' => $filePool,
            'poolFiles' => $poolFiles,
            'folders' => $folders,
            'currentFolder' => $currentFolder,
            'breadcrumb' => $currentFolder ? $currentFolder->breadcrumb() : [],
            'canMoveFiles' => $filePool ? $this->canMoveFiles($filePool) : false,
            'teams' => $this->readOnly
                ? collect()
                : Team::where('personal_team', false)->orderBy('name')->get(),
        ]);
    }
}
