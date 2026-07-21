<?php

namespace App\Livewire\Admin;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentVersion;
use App\Models\Team;
use App\Services\ManagedDocumentNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedDocuments extends Component
{
    use WithFileUploads;

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

    public function mount(): void
    {
        Gate::authorize('files.manage');
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
            $this->storeVersion($document, $this->changeNotes);
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

        $document = ManagedDocument::findOrFail($this->uploadDocumentId);
        $this->storeVersion($document, $this->changeNotes);
        $notifier->notify($document->fresh(['currentVersion', 'teams']), 'updated');

        $this->versionUploadOpen = false;
        $this->reset(['uploadDocumentId', 'upload', 'changeNotes']);
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
            'editingId', 'title', 'description', 'teamIds', 'upload', 'changeNotes',
        ]);
        $this->audienceType = ManagedDocument::AUDIENCE_ALL;
        $this->notifyOnUpdate = true;
        $this->isActive = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        $documents = ManagedDocument::query()
            ->with(['currentVersion.file', 'teams'])
            ->withCount('versions')
            ->orderByDesc('content_updated_at')
            ->orderBy('title')
            ->get();

        $historyDocument = $this->historyDocumentId
            ? ManagedDocument::with(['versions.file', 'versions.creator'])->find($this->historyDocumentId)
            : null;

        return view('livewire.admin.managed-documents', [
            'documents' => $documents,
            'teams' => Team::query()->where('personal_team', false)->orderBy('name')->get(),
            'historyDocument' => $historyDocument,
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
