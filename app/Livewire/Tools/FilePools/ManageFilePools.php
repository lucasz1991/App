<?php

namespace App\Livewire\Tools\FilePools;

use Livewire\Component;
use App\Models\FilePool;
use App\Models\File;
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

    public bool $openFileForm = false;
    public bool $openEditFileForm = false;

    public bool $readOnly = true;

    /** Freigabe pro Datei fuer Rollen aktivieren (zentrale Dateiverwaltung) */
    public bool $allowRoleSharing = false;

    /** Nur Dateien anzeigen, die fuer diese Rolle freigegeben sind */
    public ?string $roleFilter = null;

    public function mount(
        ?string $modelType = null,
        ?int $modelId = null,
        bool $readOnly = true,
        ?int $poolId = null,
        bool $allowRoleSharing = false,
        ?string $roleFilter = null,
    ): void {
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
                'user_id'    => Auth::user()->id ?? null,
                'name'       => $filename,
                'path'       => $path,
                'mime_type'  => $mime,
                'size'       => $uploadedFile->getSize(),
                'expires_at' => $this->expires[$filePoolId] ?? null,
            ]);
        }
        unset($this->fileUploads[$filePoolId], $this->expires[$filePoolId]);
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
        abort_if($this->roleFilter !== null && ! $file->isSharedWithRole($this->roleFilter), 403);

        return $file->download(); // zentral im Model
    }

    public function editFile($id)
    {
        abort_if($this->readOnly, 403);

        $this->file = File::findOrFail($id);
        $this->selectedFileName = $this->file->name;
        $this->selectedFileExpiresDate = $this->file->expires_at?->format('Y-m-d') ?? '';
        $this->selectedFileShareRoles = is_array($this->file->shared_roles) ? $this->file->shared_roles : [];
        $this->openEditFileForm = true;
    }

    public function safeFile()
    {
        abort_if($this->readOnly, 403);

        $this->validate([
            'selectedFileName' => 'required|string|max:255',
            'selectedFileExpiresDate' => 'nullable|date|after_or_equal:today',
        ]);

        if (!$this->file) {
            $this->addError('file', __('app.no_file_selected'));
            return;
        }

        $payload = [
            'name' => $this->selectedFileName,
            'expires_at' => $this->selectedFileExpiresDate ?: null,
        ];

        if ($this->allowRoleSharing) {
            $payload['shared_roles'] = array_values(array_intersect(
                $this->selectedFileShareRoles,
                array_keys(File::shareableRoles())
            ));
        }

        $this->file->update($payload);

        $this->reset(['file', 'selectedFileName', 'selectedFileExpiresDate', 'selectedFileShareRoles', 'openEditFileForm']);
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
     * Dateien des Pools unter Beruecksichtigung des Rollenfilters.
     */
    protected function visibleFiles()
    {
        $files = $this->filePool->files()->get();

        if ($this->roleFilter !== null) {
            $files = $files->filter(
                fn (File $file) => $file->isSharedWithRole($this->roleFilter)
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

        $poolFiles = $filePool ? $filePool->files()->latest()->get() : collect();

        if ($this->roleFilter !== null) {
            $poolFiles = $poolFiles->filter(
                fn (File $file) => $file->isSharedWithRole($this->roleFilter)
            )->values();
        }

        return view('livewire.tools.file-pools.manage-file-pools', [
            'filePool' => $filePool,
            'poolFiles' => $poolFiles,
        ]);
    }
}
