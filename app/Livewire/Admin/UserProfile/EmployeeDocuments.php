<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\EmployeeDocumentRequirement;
use App\Models\User;
use App\Services\Operations\EmployeeDocumentVersionService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocuments extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $userId;

    public bool $historyOpen = false;

    #[Locked]
    public string $historyType = '';

    #[Locked]
    public array $currentFileIds = [];

    /** @var array<string, TemporaryUploadedFile|null> */
    public array $uploads = [];

    public function mount(int $userId): void
    {
        app(EmployeeDocumentVersionService::class)->authorize(auth()->user(), $userId);
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

        app(EmployeeDocumentVersionService::class)->save($this->userId, $type, $validated['uploads'][$type], auth()->user(), $this->currentFileIds[$type] ?? null);

        unset($this->uploads[$type]);
        $this->dispatch('swal:toast', type: 'success', text: __('app.employee_document_saved'));
    }

    public function remove(string $type): void
    {
        Gate::authorize('employees.master-data.edit');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        app(EmployeeDocumentVersionService::class)->withdraw($this->userId, $type, auth()->user(), $this->currentFileIds[$type] ?? null);

        $this->dispatch('swal:toast', type: 'success', text: __('app.employee_document_removed'));
    }

    public function download(string $type): StreamedResponse
    {
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        return app(EmployeeDocumentVersionService::class)->download($this->userId, $type, null, auth()->user());
    }

    public function showHistory(string $type): void
    {
        app(EmployeeDocumentVersionService::class)->authorize(auth()->user(), $this->userId);
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);
        $this->historyType = $type;
        $this->historyOpen = true;
    }

    public function downloadVersion(int $versionId): StreamedResponse
    {

        return app(EmployeeDocumentVersionService::class)->download($this->userId, $this->historyType, $versionId, auth()->user());
    }

    public function render()
    {
        app(EmployeeDocumentVersionService::class)->authorize(auth()->user(), $this->userId);
        $requirements = EmployeeDocumentRequirement::query()
            ->with('file')
            ->where('user_id', $this->userId)
            ->get()
            ->keyBy('document_type');

        $this->currentFileIds = $requirements->map(fn ($requirement) => $requirement->file?->id)->all();

        return view('livewire.admin.user-profile.employee-documents', [
            'requirements' => $requirements,
            'types' => EmployeeDocumentRequirement::TYPES,
            'canEdit' => EmployeeDocumentVersionService::ready() && Gate::allows('employees.master-data.edit'),
            'versioningReady' => EmployeeDocumentVersionService::ready(),
            'versions' => EmployeeDocumentVersionService::ready() && $this->historyOpen
                ? EmployeeDocumentRequirement::where('user_id', $this->userId)->where('document_type', $this->historyType)->first()?->versions()->with('file')->get() ?? collect() : collect(),
        ]);
    }
}
