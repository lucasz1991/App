<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\EmployeeDocumentRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocuments extends Component
{
    use WithFileUploads;

    public int $userId;

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $uploads = [];

    public function mount(int $userId): void
    {
        Gate::authorize('employees.master-data.view');
        User::findOrFail($userId);
        $this->userId = $userId;
    }

    public function save(string $type): void
    {
        Gate::authorize('employees.master-data.edit');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        $validated = $this->validate([
            'uploads.'.$type => ['required', 'file', 'max:12288', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ], [], [
            'uploads.'.$type => EmployeeDocumentRequirement::TYPES[$type],
        ]);

        $upload = $validated['uploads'][$type];
        $path = $upload->store('uploads/employee-documents/'.$this->userId.'/'.$type, 'private');

        try {
            $requirement = EmployeeDocumentRequirement::firstOrCreate([
                'user_id' => $this->userId,
                'document_type' => $type,
            ]);
            $oldFile = $requirement->file;

            DB::transaction(function () use ($requirement, $upload, $path): void {
                $mime = Storage::disk('private')->mimeType($path) ?: $upload->getClientMimeType();

                $requirement->file()->create([
                    'user_id' => auth()->id(),
                    'name' => $upload->getClientOriginalName(),
                    'path' => $path,
                    'disk' => 'private',
                    'mime_type' => $mime,
                    'type' => 'employee-document',
                    'size' => $upload->getSize(),
                ]);
            });

            $oldFile?->delete();
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        activity('employee-master-data')
            ->causedBy(auth()->user())
            ->performedOn(User::findOrFail($this->userId))
            ->withProperties(['target_user_id' => $this->userId, 'document_type' => $type])
            ->log('employee_document_uploaded');

        unset($this->uploads[$type]);
        $this->dispatch('swal:toast', type: 'success', text: __('app.employee_document_saved'));
    }

    public function remove(string $type): void
    {
        Gate::authorize('employees.master-data.edit');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        $requirement = EmployeeDocumentRequirement::query()
            ->where('user_id', $this->userId)
            ->where('document_type', $type)
            ->firstOrFail();

        $requirement->file?->delete();

        activity('employee-master-data')
            ->causedBy(auth()->user())
            ->performedOn(User::findOrFail($this->userId))
            ->withProperties(['target_user_id' => $this->userId, 'document_type' => $type])
            ->log('employee_document_removed');

        $this->dispatch('swal:toast', type: 'success', text: __('app.employee_document_removed'));
    }

    public function download(string $type): StreamedResponse
    {
        Gate::authorize('employees.master-data.view');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        $requirement = EmployeeDocumentRequirement::query()
            ->with('file')
            ->where('user_id', $this->userId)
            ->where('document_type', $type)
            ->firstOrFail();
        abort_unless($requirement->file, 404);

        return $requirement->file->download($requirement->file->disk ?: 'private', denyExpired: false);
    }

    public function render()
    {
        $requirements = EmployeeDocumentRequirement::query()
            ->with('file')
            ->where('user_id', $this->userId)
            ->get()
            ->keyBy('document_type');

        return view('livewire.admin.user-profile.employee-documents', [
            'requirements' => $requirements,
            'types' => EmployeeDocumentRequirement::TYPES,
            'canEdit' => Gate::allows('employees.master-data.edit'),
        ]);
    }
}
