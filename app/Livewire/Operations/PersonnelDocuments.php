<?php

namespace App\Livewire\Operations;

use App\Models\EmployeeDocumentRequirement;
use App\Models\EmployeeDocumentVersion;
use App\Services\Operations\EmployeeDocumentVersionService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonnelDocuments extends Component
{
    #[Locked]
    public bool $personal = true;

    #[Locked]
    public string $type = '';

    public bool $historyOpen = false;

    private function access(): void
    {
        OperationsAccess::own(auth()->user(), auth()->id());
    }

    public function showHistory(string $type): void
    {
        $this->access();
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);
        $this->type = $type;
        $this->historyOpen = true;
    }

    public function download(string $type, ?int $versionId = null)
    {
        $this->access();

        return app(EmployeeDocumentVersionService::class)->download(auth()->id(), $type, $versionId, auth()->user());
    }

    public function acknowledge(int $versionId): void
    {
        $this->access();
        app(EmployeeDocumentVersionService::class)->acknowledge($versionId, auth()->user());
    }

    public function render()
    {
        $this->access();
        $ready = EmployeeDocumentVersionService::ready();
        $requirements = $ready ? EmployeeDocumentRequirement::where('user_id', auth()->id())->with(['file', 'versions'])->get() : collect();

        return view('livewire.operations.personnel-documents', ['ready' => $ready, 'requirements' => $requirements,
            'versions' => $ready && $this->historyOpen ? EmployeeDocumentVersion::whereHas('requirement', fn ($q) => $q->where('user_id', auth()->id())->where('document_type', $this->type))->orderByDesc('revision')->get() : collect()]);
    }
}
