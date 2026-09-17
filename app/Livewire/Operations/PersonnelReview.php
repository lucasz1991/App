<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\QualificationType;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PersonnelReview extends Component
{
    use WithPagination;

    #[Locked]
    public string $module;

    public string $filter = 'pending';

    public string $search = '';

    public string $validity = 'all';

    public function updatedValidity(): void
    {
        $this->access();
        abort_unless(in_array($this->validity, ['all', 'expired', '30', '90', 'future'], true), 422);
        if ($this->validity !== 'all') {
            $this->filter = 'approved';
        }
        $this->resetPage();
    }

    public array $notes = [];

    public bool $detailOpen = false;

    public bool $formOpen = false;

    public bool $typesOpen = false;

    #[Locked]
    public ?int $selectedId = null;

    public function openDetails(int $id): void
    {
        $this->access();
        abort_if($this->module === 'rules', 404);
        ($this->module === 'qualifications' ? EmployeeQualification::query() : AbsenceRequest::query())->findOrFail($id);
        $this->selectedId = $id;
        $this->notes = [];
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function createRules(): void
    {
        $this->access();
        OperationsAccess::authorize(auth()->user(), 'operations.rules.manage');
        $this->reset('rules');
        $this->resetValidation();
        $this->formOpen = true;
    }

    public string $typeName = '';

    public array $rules = ['name' => '', 'minimum_rest_minutes' => '', 'maximum_shift_minutes' => '', 'break_after_minutes' => '', 'minimum_break_minutes' => '', 'confirmed' => false];

    public function mount(string $module): void
    {
        $this->module = $module;
        $this->access();
    }

    public function updatedFilter(): void
    {
        $this->validity = 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function access(): void
    {
        abort_unless(in_array($this->module, ['qualifications', 'absences', 'rules'], true), 404);
        OperationsAccess::authorize(auth()->user(), OperationsNavigation::modules()[$this->module]['ability']);
        OperationsAccess::requireReady();
    }

    public function decide(int $id, int $revision, string $action, PersonnelWorkflowService $service): void
    {
        $this->access();
        if ($this->module === 'qualifications') {
            $service->qualification(EmployeeQualification::findOrFail($id), $revision, $action, $this->notes[$id] ?? '', auth()->user());
        } elseif ($this->module === 'absences') {
            $service->absence(AbsenceRequest::findOrFail($id), $revision, $action, $this->notes[$id] ?? '', auth()->user());
        } else {
            abort(404);
        }
        unset($this->notes[$id]);
        $this->detailOpen = false;
        $this->resetValidation();
    }

    public function addType(): void
    {
        $this->access();
        OperationsAccess::authorize(auth()->user(), 'operations.qualifications.manage');
        $this->typeName = trim($this->typeName);
        $this->validate(['typeName' => 'required|string|max:180|unique:qualification_types,name']);
        $type = QualificationType::create(['name' => $this->typeName, 'is_active' => true]);
        app(OperationsAuditService::class)->record($type, auth()->user(), 'qualification_type.created');
        $this->typeName = '';
    }

    public function saveRules(PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->saveRules($this->rules, auth()->user());
        $this->rules['confirmed'] = false;
        $this->formOpen = false;
        session()->flash('operations.saved', 'Regelprofil gespeichert.');
    }

    public function render()
    {
        $this->access();
        $query = $this->module === 'absences' ? AbsenceRequest::with('user:id,name') : EmployeeQualification::with(['user:id,name', 'type']);
        $today = now(config('operations.display_timezone', 'Europe/Berlin'))->startOfDay();
        $selected = $this->selectedId && $this->module !== 'rules' ? (clone $query)->find($this->selectedId) : null;
        if ($this->module === 'qualifications' && $this->validity !== 'all') {
            $query->where('status', 'approved');
            if ($this->validity === 'expired') {
                $query->whereDate('valid_until', '<', $today->toDateString());
            } elseif ($this->validity === 'future') {
                $query->whereDate('valid_from', '>', $today->toDateString());
            } elseif (in_array($this->validity, ['30', '90'], true)) {
                $query->whereDate('valid_until', '>=', $today->toDateString())->whereDate('valid_until', '<=', $today->copy()->addDays((int) $this->validity)->toDateString());
            }
        }

        return view('livewire.operations.personnel-review', [
            'selectedRecord' => $selected,
            'records' => $this->module === 'rules' ? null : $query->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))->when(filled($this->search), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%')))->latest()->paginate(15),
            'types' => $this->module === 'qualifications' ? QualificationType::orderBy('name')->get() : collect(),
            'activeRules' => $this->module === 'rules' ? OperationsRuleProfile::where('is_active', true)->first() : null,
        ]);
    }
}
