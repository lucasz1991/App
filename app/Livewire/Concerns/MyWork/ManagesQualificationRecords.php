<?php

namespace App\Livewire\Concerns\MyWork;

use App\Models\EmployeeQualification;
use App\Models\QualificationType;
use App\Services\Operations\PersonnelWorkflowService;
use Illuminate\Support\Facades\Schema;

/**
 * Fokusansicht „Qualifikationen": Nachweis hochladen und eigene Nachweise listen
 * (Paginator qualificationsPage). Der Datei-Upload braucht WithFileUploads in der Komponente.
 *
 * Erwartet in der Komponente: access(), withFormErrors() und $tab.
 */
trait ManagesQualificationRecords
{
    abstract private function access(): void;
    abstract private function withFormErrors(string $prefix, callable $action): mixed;

    public bool $qualificationOpen = false;

    public array $qualification = ['qualification_type_id' => '', 'valid_from' => '', 'valid_until' => ''];

    public $evidence;

    public function upload(PersonnelWorkflowService $service): void
    {
        $this->access();
        $this->resetValidation();
        $this->validate(['evidence' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('operations.evidence_max_kilobytes')]);
        $this->withFormErrors('qualification', fn () => $service->submitQualification(auth()->user(), $this->qualification, $this->evidence));
        $this->reset(['evidence', 'qualification']);
        $this->qualificationOpen = false;
        session()->flash('operations.saved', 'Nachweis eingereicht.');
    }

    /** Daten für die Fokusansicht „Qualifikationen". */
    private function recordsViewData(): array
    {
        return [
            'showPersonnelDocuments' => $this->tab === 'records' && Schema::hasTable('employee_document_versions'),
            'qualifications' => $this->tab === 'records' ? EmployeeQualification::where('user_id', auth()->id())->with('type')->latest()->paginate(20, ['*'], 'qualificationsPage') : collect(),
            'types' => $this->tab === 'records' ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(),
        ];
    }
}
