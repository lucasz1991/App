<?php

namespace App\Livewire\Admin\UserProfile;

use App\Models\EmployeeDocumentRequirement;
use App\Models\File;
use App\Models\FilePool;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class EmployeeDocuments extends Component
{
    public int $userId;

    /** @var array<string, string> */
    public array $statuses = [];

    /** @var array<string, int|string|null> */
    public array $fileIds = [];

    public function mount(int $userId): void
    {
        Gate::authorize('employees.master-data.view');
        $this->userId = $userId;
        $this->loadChecklist();
    }

    public function save(string $type): void
    {
        Gate::authorize('employees.master-data.edit');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);

        $validated = $this->validate([
            'statuses.'.$type => ['required', Rule::in(array_keys(EmployeeDocumentRequirement::STATUSES))],
            'fileIds.'.$type => ['nullable', 'integer'],
        ]);

        $fileId = $validated['fileIds'][$type] ?: null;
        if ($fileId !== null) {
            $poolId = User::findOrFail($this->userId)->filePool?->id;
            abort_unless($poolId && File::query()
                ->whereKey($fileId)
                ->where('fileable_type', FilePool::class)
                ->where('fileable_id', $poolId)
                ->exists(), 403);
        }

        $status = $validated['statuses'][$type];
        $verified = $status === 'verified';

        EmployeeDocumentRequirement::updateOrCreate(
            ['user_id' => $this->userId, 'document_type' => $type],
            [
                'status' => $status,
                'file_id' => $fileId,
                'verified_by' => $verified ? auth()->id() : null,
                'verified_at' => $verified ? now() : null,
            ]
        );

        $target = User::findOrFail($this->userId);
        activity('employee-master-data')
            ->causedBy(auth()->user())
            ->performedOn($target)
            ->withProperties(['target_user_id' => $target->id, 'document_type' => $type])
            ->log('employee_document_requirement_updated');

        $this->loadChecklist();
        $this->dispatch('swal:toast', type: 'success', text: __('app.employee_document_saved'));
    }

    private function loadChecklist(): void
    {
        $stored = EmployeeDocumentRequirement::query()
            ->where('user_id', $this->userId)
            ->get()
            ->keyBy('document_type');

        foreach (EmployeeDocumentRequirement::TYPES as $type => $label) {
            $this->statuses[$type] = $stored->get($type)?->status ?? 'missing';
            $this->fileIds[$type] = $stored->get($type)?->file_id;
        }
    }

    public function render()
    {
        $user = User::findOrFail($this->userId);
        $requirements = EmployeeDocumentRequirement::query()
            ->with(['file:id,name', 'verifier:id,name'])
            ->where('user_id', $this->userId)
            ->get()
            ->keyBy('document_type');
        $files = $user->filePool?->files()->orderBy('name')->get(['files.id', 'files.name']) ?? collect();

        return view('livewire.admin.user-profile.employee-documents', [
            'requirements' => $requirements,
            'files' => $files,
            'types' => EmployeeDocumentRequirement::TYPES,
            'availableStatuses' => EmployeeDocumentRequirement::STATUSES,
            'canEdit' => Gate::allows('employees.master-data.edit'),
        ]);
    }
}
