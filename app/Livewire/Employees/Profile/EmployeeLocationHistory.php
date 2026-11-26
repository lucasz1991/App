<?php

namespace App\Livewire\Profile;

use App\Services\EmployeeLocation\EmployeeLocationHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class EmployeeLocationHistory extends Component
{
    use WithoutUrlPagination;
    use WithPagination {
        setPage as private setHistoryPage;
    }

    #[Locked]
    public int $userId;

    public string $selectedDay = '';

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->authorizeProfile();
        $this->selectedDay = CarbonImmutable::now(EmployeeLocationHistoryService::ZONE)->toDateString();
    }

    public function hydrate(): void
    {
        $this->authorizeProfile();
    }

    public function updating($property, $value): void
    {
        $this->authorizeProfile();
        if ($property === 'selectedDay') {
            app(EmployeeLocationHistoryService::class)->validateDay((string) $value);
        }
    }

    public function updatedSelectedDay(): void
    {
        $this->authorizeProfile();
        app(EmployeeLocationHistoryService::class)->validateDay($this->selectedDay);
        $this->resetPage(EmployeeLocationHistoryService::PAGE_NAME);
    }

    public function previousDay(): void
    {
        $this->moveDay(-1);
    }

    public function nextDay(): void
    {
        $this->moveDay(1);
    }

    public function refreshHistory(): void
    {
        $this->authorizeProfile();
        app(EmployeeLocationHistoryService::class)->validateDay($this->selectedDay);
        $this->resetPage(EmployeeLocationHistoryService::PAGE_NAME);
    }

    public function setPage($page, $pageName = 'page'): void
    {
        $this->authorizeProfile();
        if ($pageName !== EmployeeLocationHistoryService::PAGE_NAME || filter_var($page, FILTER_VALIDATE_INT) === false || $page < 1) {
            throw ValidationException::withMessages(['locationHistoryPage' => 'Ungültige Standortseite.']);
        }
        $this->setHistoryPage((int) $page, $pageName);
    }

    public function render(EmployeeLocationHistoryService $service): View
    {
        $this->authorizeProfile();
        try {
            $service->validateDay($this->selectedDay);
        } catch (ValidationException $exception) {
            // Livewire can still apply a property after a validation error in
            // an updating hook. Recover to a bounded day before rendering;
            // never pass the invalid range into the sensitive point query.
            $this->selectedDay = CarbonImmutable::now(EmployeeLocationHistoryService::ZONE)->toDateString();
            $this->paginators[EmployeeLocationHistoryService::PAGE_NAME] = 1;
            $this->addError('selectedDay', $exception->errors()['selectedDay'][0]);
        }
        $view = $service->read($this->userId, $this->selectedDay, max(1, (int) $this->getPage(EmployeeLocationHistoryService::PAGE_NAME)));
        if ($view['records'] !== null) {
            $this->paginators[EmployeeLocationHistoryService::PAGE_NAME] = $view['records']->currentPage();
        }

        // Coordinates exist only in this private render projection, never in
        // public Livewire state, query strings, sessions or a persistent cache.
        return view('livewire.profile.employee-location-history', ['locationHistory' => $view]);
    }

    private function authorizeProfile(): void
    {
        app(EmployeeLocationHistoryService::class)->authorizeProfileAccess($this->userId);
    }

    private function moveDay(int $offset): void
    {
        $this->authorizeProfile();
        $service = app(EmployeeLocationHistoryService::class);
        $day = $service->validateDay($this->selectedDay)->addDays($offset);
        $today = CarbonImmutable::now(EmployeeLocationHistoryService::ZONE)->startOfDay();
        $firstDay = CarbonImmutable::now('UTC')->subDays(7)->setTimezone(EmployeeLocationHistoryService::ZONE)->startOfDay();
        if ($day->lt($firstDay) || $day->gt($today)) {
            return;
        }
        $this->selectedDay = $day->toDateString();
        $this->resetPage(EmployeeLocationHistoryService::PAGE_NAME);
    }
}
