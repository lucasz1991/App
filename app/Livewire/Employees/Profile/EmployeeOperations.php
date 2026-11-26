<?php

namespace App\Livewire\Profile;

use App\Services\Operations\EmployeeOperationsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class EmployeeOperations extends Component
{
    use WithoutUrlPagination;
    use WithPagination {
        setPage as private setOperationsPage;
    }

    #[Locked]
    public int $userId;

    #[Locked]
    public string $viewMode = 'overview';

    public string $period = 'upcoming';

    public string $search = '';

    public function mount(int $userId, string $viewMode = 'overview'): void
    {
        $this->userId = $userId;
        $this->viewMode = $viewMode;
        $this->authorizeProfile();
        abort_unless(in_array($viewMode, ['overview', 'shifts'], true), 422);
        $this->paginators[EmployeeOperationsService::PAGE_NAME] = 1;
    }

    public function hydrate(): void
    {
        $this->authorizeProfile();
    }

    public function updating($property, $value): void
    {
        $this->authorizeProfile();
        $service = app(EmployeeOperationsService::class);
        if ($property === 'period') {
            $service->validateFilters((string) $value, $this->search);
        } elseif ($property === 'search') {
            $service->validateFilters($this->period, (string) $value);
        } elseif ($property === 'paginators' || str_starts_with($property, 'paginators.')) {
            if ($property !== 'paginators.'.EmployeeOperationsService::PAGE_NAME) {
                throw ValidationException::withMessages(['employeeShiftsPage' => 'Ungültige Schichtseite.']);
            }
            $this->validatePage($value, EmployeeOperationsService::PAGE_NAME);
        }
    }

    public function updatedPeriod(): void
    {
        $this->authorizeProfile();
        app(EmployeeOperationsService::class)->validateFilters($this->period, $this->search);
        $this->resetPage(EmployeeOperationsService::PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        $this->authorizeProfile();
        app(EmployeeOperationsService::class)->validateFilters($this->period, $this->search);
        $this->resetPage(EmployeeOperationsService::PAGE_NAME);
    }

    public function setPeriod(string $period): void
    {
        $this->authorizeProfile();
        app(EmployeeOperationsService::class)->validateFilters($period, $this->search);
        $this->period = $period;
        $this->resetPage(EmployeeOperationsService::PAGE_NAME);
    }

    public function setSearch(string $search): void
    {
        $this->authorizeProfile();
        app(EmployeeOperationsService::class)->validateFilters($this->period, $search);
        $this->search = trim($search);
        $this->resetPage(EmployeeOperationsService::PAGE_NAME);
    }

    public function refreshOperations(): void
    {
        $this->authorizeProfile();
        app(EmployeeOperationsService::class)->validateFilters($this->period, $this->search);
        $this->resetPage(EmployeeOperationsService::PAGE_NAME);
    }

    public function openShift(int $shiftId): void
    {
        $this->authorizeProfile();
        $url = app(EmployeeOperationsService::class)->shiftUrl($this->userId, $shiftId);
        $this->redirect($url, navigate: true);
    }

    public function setPage($page, $pageName = 'page'): void
    {
        $this->authorizeProfile();
        $this->validatePage($page, $pageName);
        $this->setOperationsPage((int) $page, $pageName);
    }

    public function render(EmployeeOperationsService $service): View
    {
        $this->authorizeProfile();
        try {
            $service->validateFilters($this->period, $this->search);
        } catch (ValidationException $exception) {
            // Failed updating hooks can leave changed public properties in a
            // Livewire render. Recover without issuing an invalid query.
            $this->period = 'upcoming';
            $this->search = '';
            $this->paginators[EmployeeOperationsService::PAGE_NAME] = 1;
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
        $page = $this->paginators[EmployeeOperationsService::PAGE_NAME] ?? 1;
        $this->validatePage($page, EmployeeOperationsService::PAGE_NAME);
        $view = $service->read($this->userId, $this->viewMode, $this->period, $this->search, (int) $page);
        if ($view['records'] !== null) {
            $this->paginators[EmployeeOperationsService::PAGE_NAME] = $view['records']->currentPage();
        }

        return view('livewire.profile.employee-operations', ['employeeOperations' => $view]);
    }

    private function authorizeProfile(): void
    {
        app(EmployeeOperationsService::class)->authorizeProfileAccess($this->userId);
    }

    private function validatePage(mixed $page, string $pageName): void
    {
        if ($pageName !== EmployeeOperationsService::PAGE_NAME || filter_var($page, FILTER_VALIDATE_INT) === false || $page < 1 || $page > EmployeeOperationsService::MAX_PAGE) {
            throw ValidationException::withMessages(['employeeShiftsPage' => 'Ungültige Schichtseite.']);
        }
    }
}
