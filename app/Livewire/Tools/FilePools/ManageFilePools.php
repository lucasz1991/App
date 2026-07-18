<?php

namespace App\Livewire\Tools\FilePools;

use Livewire\Component;
use App\Models\FilePool;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\Team;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;
use Illuminate\Support\Facades\Auth;

class ManageFilePools extends Component
{
    use WithPagination;
    use WithFileUploads;

    public ?string $modelType = null;
    public ?int $modelId = null;

    public ?int $filePoolId = null;
    public ?FilePool $filePool = null;

    public array $fileUploads = [];
    public array $selectedFiles = [];
    public array $expires = [];

    public ?File $file = null;
    public string $selectedFileName;
    public string $selectedFileExpiresDate;
    public array $selectedFileShareRoles = [];

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

    public bool $readOnly = true;

    /** Freigabe pro Datei fuer Rollen aktivieren (zentrale Dateiverwaltung) */
    public bool $allowRoleSharing = false;

    /** Nur Dateien anzeigen, die fuer diese Rolle freigegeben sind */
    public ?string $roleFilter = null;

    /** Explorer: aktuell geoeffneter Ordner (null = Wurzel) */
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

    /** Ordner-Rechte (Rolle => Aktion => bool) */
    public bool $openFolderPermissions = false;
    public ?int $permissionsFolderId = null;
    public array $folderPermissions = [];

    public function mount(
        ?string $modelType = null,
        ?int $modelId = null,
        bool $readOnly = true,
        ?int $poolId = null,
        bool $allowRoleSharing = false,
        ?string $roleFilter = null,
    ): void {
        if ($modelType === \App\Models\Team::class && $modelId !== null) {
            abort_unless(Auth::user()?->belongsToTeam(\App\Models\Team::findOrFail($modelId)), 403);
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
        $this->allowRoleSharing = $allowRoleSharing;
        $this->roleFilter = $roleFilter;
    }

    /* ------------------------------------------------------------------
     * Explorer: Navigation & Ordnerverwaltung
     * ----------------------------------------------------------------*/

    /**
     * In einen Ordner wechseln (null = Wurzel). Prueft Pool-Zugehoerigkeit
     * und — im Rollenmodus — das Ansehen-Recht des Ordners.
     */
    public function enterFolder(?int $folderId = null): void
    {
        if ($folderId === null) {
            $this->currentFolderId = null;
            return;
        }

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);

        if ($this->roleFilter !== null && ! $folder->allowsForRole($this->roleFilter, 'view')) {
            abort(403);
        }

        if ($this->roleFilter !== null && ! $folder->isPubliclyVisible(Auth::user())) {
            abort(403);
        }

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
        abort_if($this->readOnly || ! $this->allowRoleSharing, 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($folderId);
        $this->permissionsFolderId = $folder->id;

        $stored = is_array($folder->permissions) ? $folder->permissions : [];
        $this->folderPermissions = [];

        foreach (array_keys(File::shareableRoles()) as $role) {
            foreach (array_keys(FileFolder::permissionActions()) as $action) {
                $this->folderPermissions[$role][$action] = (bool) ($stored[$role][$action] ?? false);
            }
        }

        $this->openFolderPermissions = true;
    }

    public function savePermissions(): void
    {
        abort_if($this->readOnly || ! $this->allowRoleSharing, 403);

        $folder = FileFolder::where('file_pool_id', $this->filePoolId)->findOrFail($this->permissionsFolderId);

        $payload = [];
        foreach (array_keys(File::shareableRoles()) as $role) {
            foreach (array_keys(FileFolder::permissionActions()) as $action) {
                $payload[$role][$action] = (bool) ($this->folderPermissions[$role][$action] ?? false);
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
            "fileUploads.$filePoolId"     => ['required', 'array', 'min:1'],
            "fileUploads.$filePoolId.*"   => ['file', 'max:302400'], // 300 MB je Datei
            "expires.$filePoolId"         => ['nullable', 'date', 'after:today'],
        ]);

        foreach ($this->fileUploads[$filePoolId] as $uploadedFile) {
            $filename = $uploadedFile->getClientOriginalName();
            $path     = $uploadedFile->store('uploads/files', 'private');
            $mime     = Storage::disk('private')->mimeType($path) ?? $uploadedFile->getClientMimeType();

            $this->filePool->files()->create([
                'folder_id'    => $this->currentFolderId,
                'user_id'      => Auth::user()->id ?? null,
                'name'         => $filename,
                'path'         => $path,
                'mime_type'    => $mime,
                'size'         => $uploadedFile->getSize(),
                'expires_at'   => $this->expires[$filePoolId] ?? null,
                'visible_from' => $this->uploadVisibleFrom ?: null,
                'auto_delete'  => (bool) $this->uploadAutoDelete,
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
        $file = File::findOrFail($fileId);

        // Nur Dateien dieses Pools; bei Rollenfilter nur freigegebene
        abort_if((int) $file->fileable_id !== (int) $this->filePoolId, 403);
        abort_if($this->roleFilter !== null && ! $this->fileVisibleForRole($file, 'download'), 403);
        abort_if($this->roleFilter !== null && ! $file->isPubliclyVisible(Auth::user()), 403);

        return $file->download(); // zentral im Model
    }

    /**
     * Sichtbarkeit/Downloadrecht einer Datei im Rollenmodus:
     * Dateien in Ordnern erben das Ordner-Recht, Wurzeldateien
     * nutzen weiterhin die dateibezogene Freigabe (shared_roles).
     */
    protected function fileVisibleForRole(File $file, string $action = 'view'): bool
    {
        if ($file->folder_id) {
            $folder = $file->folder;

            return $folder ? $folder->allowsForRole($this->roleFilter, $action) : false;
        }

        return $file->isSharedWithRole($this->roleFilter);
    }

    public function editFile($id)
    {
        abort_if($this->readOnly, 403);

        $this->file = File::findOrFail($id);
        $this->selectedFileName = $this->file->name;
        $this->selectedFileExpiresDate = $this->file->expires_at?->format('Y-m-d') ?? '';
        $this->selectedFileShareRoles = is_array($this->file->shared_roles) ? $this->file->shared_roles : [];
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

        if (!$this->file) {
            $this->addError('file', __('app.no_file_selected'));
            return;
        }

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

        if ($this->allowRoleSharing) {
            $payload['shared_roles'] = array_values(array_intersect(
                $this->selectedFileShareRoles,
                array_keys(File::shareableRoles())
            ));
        }

        $this->file->update($payload);

        $this->reset([
            'file', 'selectedFileName', 'selectedFileExpiresDate', 'selectedFileShareRoles', 'openEditFileForm',
            'selectedFileVisibleFrom', 'selectedFileAutoDelete', 'selectedFileVisibleTeams',
        ]);
        $this->filePool->refresh();
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_saved'));
    }

    /**
     * Gemeinsamer Helper: erzeugt ein ZIP unter storage_path("app/private/zips")
     * und liefert eine BinaryFileResponse, die die ZIP-Datei nach dem Senden loescht.
     *
     * @param  string  $baseName (ohne .zip)
     * @param  \Illuminate\Support\Collection|\Traversable|array  $files  Sammlung von File-Modellen
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    protected function buildZipResponse(string $baseName, $files)
    {
        $zipFileName = trim($baseName) . '.zip';
        $zipDir      = storage_path('app/private/zips');
        $zipPath     = $zipDir . DIRECTORY_SEPARATOR . $zipFileName;

        if (!is_dir($zipDir)) {
            mkdir($zipDir, 0755, true);
        }

        $zip = new ZipArchive();
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

        $base = 'RailTime_Dateien_' . now()->format('Ymd_His');

        return $this->buildZipResponse($base, $files);
    }

    /**
     * Dateien der aktuellen Explorer-Ebene unter Beruecksichtigung des
     * Rollenfilters (Ordnerrechte bzw. shared_roles auf Wurzelebene).
     */
    protected function visibleFiles()
    {
        $files = $this->filePool->files()
            ->where(fn ($q) => $this->currentFolderId === null
                ? $q->whereNull('folder_id')
                : $q->where('folder_id', $this->currentFolderId))
            ->latest()
            ->get();

        if ($this->roleFilter !== null) {
            $files = $files->filter(
                fn (File $file) => $this->fileVisibleForRole($file, 'download')
                    && $file->isPubliclyVisible(Auth::user())
            )->values();
        }

        return $files;
    }

    /**
     * Laedt ALLE nicht abgelaufenen Dateien des aktuellen Pools als ZIP.
     */
    public function downloadAll()
    {
        if (!$this->filePool) {
            abort(404, 'FilePool nicht gefunden.');
        }

        $files = $this->visibleFiles();

        $base = 'RailTime_Dateien_' . now()->format('Y_m_d-H_i_s');

        return $this->buildZipResponse($base, $files);
    }

    public function deleteFile(int $fileId)
    {
        abort_if($this->readOnly, 403);

        $file = File::findOrFail($fileId);
        Storage::disk('private')->delete($file->path);
        $file->delete();
        $this->dispatch('swal:toast', type: 'success', text: __('app.file_deleted'));
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

        // Unterordner der aktuellen Ebene (im Rollenmodus nur sichtbare)
        $folders = $filePool
            ? FileFolder::where('file_pool_id', $this->filePoolId)
                ->where(fn ($q) => $this->currentFolderId === null
                    ? $q->whereNull('parent_id')
                    : $q->where('parent_id', $this->currentFolderId))
                ->orderBy('name')
                ->get()
            : collect();

        if ($this->roleFilter !== null) {
            $folders = $folders->filter(
                fn (FileFolder $folder) => $folder->allowsForRole($this->roleFilter, 'view')
                    && $folder->isPubliclyVisible(Auth::user())
            )->values();
        }

        // Dateien der aktuellen Ebene (im Rollenmodus: Ordnerrecht bzw. shared_roles)
        $poolFiles = $filePool
            ? $filePool->files()
                ->where(fn ($q) => $this->currentFolderId === null
                    ? $q->whereNull('folder_id')
                    : $q->where('folder_id', $this->currentFolderId))
                ->latest()
                ->get()
            : collect();

        if ($this->roleFilter !== null) {
            $poolFiles = $poolFiles->filter(
                fn (File $file) => $this->fileVisibleForRole($file, 'view')
                    && $file->isPubliclyVisible(Auth::user())
            )->values();
        }

        return view('livewire.tools.file-pools.manage-file-pools', [
            'filePool' => $filePool,
            'poolFiles' => $poolFiles,
            'folders' => $folders,
            'currentFolder' => $currentFolder,
            'breadcrumb' => $currentFolder ? $currentFolder->breadcrumb() : [],
            'teams' => $this->readOnly
                ? collect()
                : Team::where('personal_team', false)->orderBy('name')->get(),
        ]);
    }
}
