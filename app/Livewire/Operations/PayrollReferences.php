<?php

namespace App\Livewire\Operations;

use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Models\EmployeePayrollReference;
use App\Models\User;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\PersonnelScopeService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PayrollReferences extends Component
{
    use ResetsPageOnFilterChange;
    use WithPagination;

    public bool $open = false;

    public bool $editOpen = false;

    public string $search = '';

    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public string $employeeName = '';

    public array $form = ['employer_reference' => '', 'personnel_number' => '', 'external_employee_reference' => ''];

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.time.export');
        abort_unless(Schema::hasTable('employee_payroll_references'), 503);
    }

    protected function pageResetProperties(): array
    {
        return ['search'];
    }

    protected function filterPageName(): string
    {
        return 'payrollPage';
    }

    public function edit(int $id): void
    {
        $this->access();
        app(PersonnelScopeService::class)->authorize(auth()->user(), $id, 'operations.time.export');
        $employee = User::where('role', 'staff')->findOrFail($id);
        $record = EmployeePayrollReference::where('user_id', $id)->first();
        $this->userId = $id;
        $this->employeeName = $employee->name;
        $this->revision = $record?->revision;
        $this->form = $record?->only(array_keys($this->form)) ?? ['employer_reference' => '', 'personnel_number' => '', 'external_employee_reference' => ''];
        $this->open = false;
        $this->editOpen = true;
        $this->resetValidation();
    }

    public function save(PayrollReferenceService $service): void
    {
        $this->access();
        abort_unless($this->editOpen && $this->userId, 422);
        try {
            $service->save($this->userId, $this->revision, $this->form, auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn ($messages, $key) => [array_key_exists($key, $this->form) ? 'form.'.$key : $key => $messages]
            )->all());
        }
        $this->returnToList();
        session()->flash('operations.saved', 'Lohnzuordnung gespeichert.');
    }

    public function updatedEditOpen(bool $open): void
    {
        if (! $open) {
            $this->returnToList();
        }
    }

    public function returnToList(): void
    {
        $this->access();
        $this->reset(['editOpen', 'userId', 'revision', 'employeeName', 'form']);
        $this->resetValidation();
        $this->open = true;
    }

    public function render()
    {
        $this->access();
        $users = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff'), auth()->user(), 'operations.time.export')->when(filled($this->search), fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))->orderBy('name')->paginate(15, ['id', 'name'], 'payrollPage');
        $refs = EmployeePayrollReference::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $users->getCollection()->each(function ($user) use ($refs) {
            $user->setAttribute('personnel_number', $refs->get($user->id)?->personnel_number);
            $user->setAttribute('employer_reference', $refs->get($user->id)?->employer_reference);
        });

        return view('livewire.operations.payroll-references', compact('users'));
    }
}
