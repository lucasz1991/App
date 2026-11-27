<?php

namespace App\Livewire\Files;

use App\Livewire\Concerns\InteractsWithFileExplorer;
use App\Models\DeviceTrustCertificate;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentVersion;
use App\Models\Team;
use App\Services\DeviceManagement\Desktop\DeviceFileSyncService;
use App\Services\DeviceManagement\Desktop\DeviceTrustService;
use App\Services\ManagedDocumentNotifier;
use App\Support\FileExplorer;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedDocuments extends Component
{
    use InteractsWithFileExplorer;
    use WithFileUploads;

    #[Locked]
    public bool $embedded = false;

    public string $search = '';

    public bool $formOpen = false;
    public bool $versionUploadOpen = false;
    public bool $historyOpen = false;
    public ?int $editingId = null;
    public ?int $uploadDocumentId = null;
    public ?int $historyDocumentId = null;
    public string $title = '';
    public string $description = '';
    public string $audienceType = ManagedDocument::AUDIENCE_ALL;
    public array $teamIds = [];
    public bool $notifyOnUpdate = true;
    public bool $isActive = true;
    public $upload = null;
    public string $changeNotes = '';

    /** Herausgeber-Zertifikat, mit dem die hochgeladene Datei signiert ist (public_id) oder leer. */
    public string $certificateId = '';

    /** Neues Zertifikat (.cer), das zusammen mit der Version aufgenommen wird. */
    public $certificateFile = null;

    public function mount(bool $embedded = false): void
    {
        Gate::authorize('files.manage');
        $this->embedded = $embedded;
        $this->sortBy = 'date';
        $this->sortDirection = 'desc';
    }

    public function clearExplorerFilters(): void
    {
        $this->search = '';
        $this->fileType = '';
    }

    public function openCreate(): void
    {
        Gate::authorize('files.manage');
        $this->resetForm();
        $this->formOpen = true;
    }

    public function openEdit(int $documentId): void
    {
        Gate::authorize('files.manage');
        $document = ManagedDocument::with('teams')->findOrFail($documentId);

        $this->editingId = $document->id;
        $this->title = $document->title;
        $this->description = (string) $document->description;
        $this->audienceType = $document->audience_type;
        $this->teamIds = $document->teams->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->notifyOnUpdate = (bool) $document->notify_on_update;
        $this->isActive = (bool) $document->is_active;
        $this->upload = null;
        $this->changeNotes = '';
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function save(ManagedDocumentNotifier $notifier): void
    {
        Gate::authorize('files.manage');

        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audienceType' => ['required', Rule::in([ManagedDocument::AUDIENCE_ALL, ManagedDocument::AUDIENCE_TEAMS])],
            'teamIds' => [Rule::requiredIf($this->audienceType === ManagedDocument::AUDIENCE_TEAMS), 'array'],
            'teamIds.*' => ['integer', 'exists:teams,id'],
            'notifyOnUpdate' => ['boolean'],
            'isActive' => ['boolean'],
        ];

        if (! $this->editingId) {
            $rules['upload'] = ['required', 'file', 'max:307200'];
            $rules['changeNotes'] = ['nullable', 'string', 'max:1000'];
        }

        $this->validate($rules);
        $validTeamIds = $this->validatedTeamIds();
        $isNew = ! $this->editingId;
        $certificate = $isNew ? $this->resolveCertificate() : null;

        $document = DB::transaction(function () use ($validTeamIds): ManagedDocument {
            $document = $this->editingId
                ? ManagedDocument::findOrFail($this->editingId)
                : new ManagedDocument(['created_by' => auth()->id()]);

            $document->fill([
                'title' => trim($this->title),
                'description' => trim($this->description) ?: null,
                'audience_type' => $this->audienceType,
                'notify_on_update' => $this->notifyOnUpdate,
                'is_active' => $this->isActive,
                'updated_by' => auth()->id(),
            ]);
            $document->save();
            $document->teams()->sync($this->audienceType === ManagedDocument::AUDIENCE_TEAMS ? $validTeamIds : []);

            return $document;
        });

        if ($isNew) {
            try {
                $version = $this->storeVersion($document, $this->changeNotes);
                $this->coupleCertificate($version, $certificate);
            } catch (\Throwable $exception) {
                $document->delete();

                throw $exception;
            }

            $notifier->notify($document->fresh(['currentVersion', 'teams']), 'created');
        }

        $this->formOpen = false;
        $this->resetForm();
        $this->dispatch('swal:toast', type: 'success', text: __('app.managed_document_saved'));
    }

    public function openVersionUpload(int $documentId): void
    {
        Gate::authorize('files.manage');
        ManagedDocument::findOrFail($documentId);

        $this->uploadDocumentId = $documentId;
        $this->upload = null;
        $this->changeNotes = '';
        $this->certificateFile = null;
        // Vorbelegt mit dem Zertifikat der bisherigen Version: meist bleibt es dasselbe.
        $this->certificateId = $this->currentCertificateId($documentId);
        $this->resetErrorBag();
        $this->versionUploadOpen = true;
    }

    public function uploadVersion(ManagedDocumentNotifier $notifier): void
    {
        Gate::authorize('files.manage');
        $this->validate([
            'uploadDocumentId' => ['required', 'integer', 'exists:managed_documents,id'],
            'upload' => ['required', 'file', 'max:307200'],
            'changeNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $certificate = $this->resolveCertificate();
        $document = ManagedDocument::findOrFail($this->uploadDocumentId);
        $this->coupleCertificate($this->storeVersion($document, $this->changeNotes), $certificate);
        $notifier->notify($document->fresh(['currentVersion', 'teams']), 'updated');

        $this->versionUploadOpen = false;
        $this->reset(['uploadDocumentId', 'upload', 'changeNotes', 'certificateId', 'certificateFile']);
        $this->dispatch('swal:toast', type: 'success', text: __('app.managed_document_version_uploaded'));
    }

    public function openHistory(int $documentId): void
    {
        Gate::authorize('files.manage');
        ManagedDocument::findOrFail($documentId);
        $this->historyDocumentId = $documentId;
        $this->historyOpen = true;
    }

    public function restoreVersion(int $versionId, ManagedDocumentNotifier $notifier): void
    {
        Gate::authorize('files.manage');
        $version = ManagedDocumentVersion::with(['document', 'file'])->findOrFail($versionId);
        abort_unless($version->file, 404);

        DB::transaction(function () use ($version): void {
            $version->document->versions()->update(['is_current' => false]);
            $version->update(['is_current' => true]);
            $version->document->update([
                'content_updated_at' => now(),
                'updated_by' => auth()->id(),
            ]);
        });

        $notifier->notify($version->document->fresh(['currentVersion', 'teams']), 'restored');
        $this->dispatch('swal:toast', type: 'success', text: __('app.managed_document_version_restored'));
    }

    /** Aktuelle Version auf Firmen-PCs verteilen (Ordner RailTime\Listen). Nur Superadmin, nur gekoppelte Versionen. */
    public function releaseToDevices(int $documentId): void
    {
        Gate::authorize('files.manage');
        $version = app(DeviceFileSyncService::class)->release(ManagedDocument::findOrFail($documentId), auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Version '.$version->version_number.' wird auf Firmen-PCs mit Office-Vertrauen abgelegt.');
    }

    public function withdrawFromDevices(int $documentId): void
    {
        Gate::authorize('files.manage');
        app(DeviceFileSyncService::class)->withdraw(ManagedDocument::findOrFail($documentId), auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Verteilung beendet. Bereits abgelegte Dateien bleiben auf den PCs.');
    }

    public function toggleActive(int $documentId): void
    {
        Gate::authorize('files.manage');
        $document = ManagedDocument::findOrFail($documentId);
        $document->update([
            'is_active' => ! $document->is_active,
            'updated_by' => auth()->id(),
        ]);
    }

    public function downloadVersion(int $versionId): StreamedResponse
    {
        Gate::authorize('files.manage');
        $version = ManagedDocumentVersion::with('file')->findOrFail($versionId);
        abort_unless($version->file, 404);

        return $version->file->download($version->file->disk ?: 'private', denyExpired: false);
    }

    protected function storeVersion(ManagedDocument $document, ?string $changeNotes): ManagedDocumentVersion
    {
        $path = $this->upload->store('uploads/managed-documents/' . $document->id, 'private');

        try {
            return DB::transaction(function () use ($document, $changeNotes, $path): ManagedDocumentVersion {
                $lockedDocument = ManagedDocument::query()->lockForUpdate()->findOrFail($document->id);
                $nextVersion = ((int) $lockedDocument->versions()->max('version_number')) + 1;
                $lockedDocument->versions()->update(['is_current' => false]);

                $version = $lockedDocument->versions()->create([
                    'version_number' => $nextVersion,
                    'is_current' => true,
                    'change_notes' => trim((string) $changeNotes) ?: null,
                    'created_by' => auth()->id(),
                ]);

                $mime = Storage::disk('private')->mimeType($path) ?: $this->upload->getClientMimeType();
                $version->file()->create([
                    'user_id' => auth()->id(),
                    'name' => $this->upload->getClientOriginalName(),
                    'path' => $path,
                    'disk' => 'private',
                    'mime_type' => $mime,
                    'type' => 'managed-document-version',
                    'size' => $this->upload->getSize(),
                ]);

                $lockedDocument->update([
                    'content_updated_at' => now(),
                    'updated_by' => auth()->id(),
                ]);

                return $version->load('file');
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    /**
     * Das Zertifikat zur hochgeladenen Datei: ein neu hochgeladenes (nur wer
     * Zertifikate verwalten darf) oder ein bereits aktives aus der Auswahl.
     * Geprüft wird vor dem Speichern der Version, damit bei einem Fehler nichts halb entsteht.
     */
    protected function resolveCertificate(): ?DeviceTrustCertificate
    {
        $trust = app(DeviceTrustService::class);
        if (! $trust->ready()) {
            return null;
        }
        if ($this->certificateFile) {
            abort_unless($trust->canManage(auth()->user()), 403);
            $this->validate(['certificateFile' => ['file', 'max:16']], ['certificateFile.max' => 'Die Datei ist zu groß für ein einzelnes Zertifikat.']);

            return $trust->store((string) file_get_contents($this->certificateFile->getRealPath()), auth()->user());
        }
        if ($this->certificateId === '') {
            return null;
        }
        $certificate = DeviceTrustCertificate::query()->where('public_id', $this->certificateId)->where('active', true)->first();
        if (! $certificate) {
            throw ValidationException::withMessages(['certificateId' => 'Dieses Zertifikat ist nicht mehr verfügbar.']);
        }

        return $certificate;
    }

    protected function coupleCertificate(ManagedDocumentVersion $version, ?DeviceTrustCertificate $certificate): void
    {
        if ($certificate) {
            app(DeviceTrustService::class)->couple($version, $certificate, auth()->user());
        }
    }

    protected function currentCertificateId(int $documentId): string
    {
        if (! app(DeviceTrustService::class)->ready()) {
            return '';
        }
        $current = ManagedDocumentVersion::query()->where('managed_document_id', $documentId)->where('is_current', true)->first();

        return (string) ($current?->trustCertificates()->where('active', true)->value('public_id') ?? '');
    }

    protected function validatedTeamIds(): array
    {
        return Team::query()
            ->where('personal_team', false)
            ->whereIn('id', array_map('intval', $this->teamIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'title', 'description', 'teamIds', 'upload', 'changeNotes', 'certificateId', 'certificateFile',
        ]);
        $this->audienceType = ManagedDocument::AUDIENCE_ALL;
        $this->notifyOnUpdate = true;
        $this->isActive = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        Gate::authorize('files.manage');
        $this->normalizeExplorerOptions();
        $trust = app(DeviceTrustService::class);
        $trustReady = $trust->ready();
        $fileSync = app(DeviceFileSyncService::class);
        $documents = ManagedDocument::query()
            ->with(['currentVersion.file', 'teams'])
            ->when($trustReady, fn ($query) => $query->with('currentVersion.trustCertificates'))
            ->withCount('versions')
            ->get();
        $fileTypes = FileExplorer::fileTypes($documents->map(fn (ManagedDocument $document) => $document->currentVersion?->file), current: $this->fileType);
        $documents = FileExplorer::sortDocuments(
            FileExplorer::filterDocuments($documents, $this->search, $this->fileType),
            $this->sortBy,
            $this->sortDirection,
        );

        $historyDocument = $this->historyDocumentId
            ? ManagedDocument::with(['versions.file', 'versions.creator'])->find($this->historyDocumentId)
            : null;

        return view('livewire.files.managed-documents', [
            'documents' => $documents,
            'fileTypes' => $fileTypes,
            'scopeLinks' => OperationsPages::documentScopeLinks($this->embedded),
            'teams' => Team::query()->where('personal_team', false)->orderBy('name')->get(),
            'historyDocument' => $historyDocument,
            'trustReady' => $trustReady,
            'canManageTrust' => $trustReady && $trust->canManage(auth()->user()),
            'certificates' => $trustReady ? DeviceTrustCertificate::query()->where('active', true)->orderBy('name')->get(['public_id', 'name', 'thumbprint_sha1', 'valid_until']) : collect(),
            'fileSyncReady' => $fileSync->ready(),
            'canReleaseToDevices' => $fileSync->ready() && $fileSync->canRelease(auth()->user()),
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
