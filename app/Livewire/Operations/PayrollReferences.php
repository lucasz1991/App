<?php

namespace App\Livewire\Operations;

use App\Models\EmployeePayrollReference;
use App\Models\User;
use App\Services\Operations\PayrollReferenceService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PayrollReferences extends Component
{
    use WithPagination;

    public bool $open = false;

    public bool $editOpen = false;

    public string $search = '';

    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public ?int $revision = null;

    public array $form = ['employer_reference' => '', 'personnel_number' => '', 'external_employee_reference' => ''];

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.time.export');
        abort_unless(Schema::hasTable('employee_payroll_references'), 503);
    }

    public function updatedSearch(): void
    {
        $this->resetPage('payrollPage');
    }

    public function edit(int $id): void
    {
        $this->access();
        User::where('role', 'staff')->findOrFail($id);
        $record = EmployeePayrollReference::where('user_id', $id)->first();
        $this->userId = $id;
        $this->revision = $record?->revision;
        $this->form = $record?->only(array_keys($this->form)) ?? ['employer_reference' => '', 'personnel_number' => '', 'external_employee_reference' => ''];
        $this->open = false;
        $this->editOpen = true;
        $this->resetValidation();
    }

    public function save(PayrollReferenceService $service): void
    {
        $this->access();
        $service->save($this->userId, $this->revision, $this->form, auth()->user());
        $this->editOpen = false;
        $this->open = true;
    }

    public function render()
    {
        $this->access();
        $users = User::where('role', 'staff')->when(filled($this->search), fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))->orderBy('name')->paginate(15, ['id', 'name'], 'payrollPage');
        $refs = EmployeePayrollReference::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $users->getCollection()->each(function ($user) use ($refs) {
            $user->setAttribute('personnel_number', $refs->get($user->id)?->personnel_number);
            $user->setAttribute('employer_reference', $refs->get($user->id)?->employer_reference);
        });

        return view('livewire.operations.payroll-references', compact('users'));
    }
}
