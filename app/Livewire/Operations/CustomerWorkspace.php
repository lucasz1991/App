<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\User;
use App\Services\Operations\CustomerWorkflowService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerWorkspace extends Component
{
    use WithPagination, WithoutUrlPagination;

    public const VIEWS = ['master' => 'Stammdaten', 'contacts' => 'Kontakte & Orte', 'conditions' => 'Konditionen', 'portal' => 'Portalverwaltung'];

    public const SECTIONS = ['access' => 'Zugänge', 'automation' => 'Automatik', 'publications' => 'Freigaben', 'delivery' => 'Versand'];

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public string $view = '';

    #[Locked]
    public string $section = 'access';

    #[Locked]
    public int $contextRevision = 0;

    #[Locked]
    public bool $startCreating = false;

    #[Locked]
    public ?int $editingCustomerId = null;

    #[Locked]
    public ?int $selectedListCustomerId = null;

    public string $search = '';

    public string $activeFilter = 'active';

    public int $perPage = 15;

    #[Locked]
    public string $sortBy = 'company_name';

    #[Locked]
    public string $sortDir = 'asc';

    public static function availableViews(User $actor): array
    {
        $actor = $actor->fresh();
        if (! $actor?->status) {
            return [];
        }
        $views = [];
        if ($actor->can('operations.manage')) {
            $views['master'] = self::VIEWS['master'];
        }
        if ($actor->can('operations.inquiries.manage') && CustomerWorkflowService::ready()) {
            $views['contacts'] = self::VIEWS['contacts'];
            $views['conditions'] = self::VIEWS['conditions'];
        }
        if (CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready()
            && $actor->can('customers.portal.manage')
            && app(CustomerPortalScope::class)->manageableCustomers($actor)->exists()) {
            $views['portal'] = self::VIEWS['portal'];
        }

        return $views;
    }

    public function mount(string $initialView = '', string $initialSection = '', array $context = []): void
    {
        abort_unless(self::availableViews($this->actor()), 403);
        $customers = $this->customers();
        $id = $context['customer'] ?? null;
        if ($id !== null && $id !== '') {
            abort_unless((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0), 404);
            $this->customerId = (int) $customers->findOrFail((int) $id)->id;
        }
        $state = array_replace($context, request()->query());
        foreach (['search', 'status', 'sort', 'direction'] as $key) {
            abort_unless(! isset($state[$key]) || is_string($state[$key]), 422);
        }
        $this->search = $state['search'] ?? '';
        $this->activeFilter = $state['status'] ?? ($this->canManage() ? 'active' : 'all');
        $this->perPage = $this->listInteger($state['per_page'] ?? 15);
        $this->sortBy = $state['sort'] ?? 'company_name';
        $this->sortDir = $state['direction'] ?? 'asc';
        $this->validateListState();
        $this->setPage($this->listInteger($state['customersPage'] ?? 1), 'customersPage');
        $views = $this->views();
        if ($initialView !== '') {
            abort_unless(isset(self::VIEWS[$initialView]), 404);
            abort_unless(isset($views[$initialView]), 403);
        }
        $this->view = $initialView ?: (array_key_first($views) ?? '');
        if ($initialSection !== '') {
            abort_unless(isset(self::SECTIONS[$initialSection]), 404);
            if ($this->customerId && $this->view === 'portal') {
                abort_unless(isset($this->sections()[$initialSection]), 403);
            }
            $this->section = $initialSection;
        }
        $this->syncUrl();
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $actor = $actor->fresh();
        abort_unless($actor?->status, 403);

        return $actor;
    }

    private function portalReady(): bool
    {
        return CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready();
    }

    private function customers(): Builder
    {
        $actor = $this->actor();
        if ($actor->can('operations.manage') || $actor->can('operations.inquiries.manage')) {
            return Customer::query()->select(['id', 'company_name']);
        }
        abort_unless($this->portalReady() && $actor->can('customers.portal.manage'), 403);

        return app(CustomerPortalScope::class)->manageableCustomers($actor)->select(['id', 'company_name']);
    }

    private function portalAllowed(string $ability): bool
    {
        if (! $this->customerId || ! $this->portalReady() || ! $this->actor()->can($ability)) {
            return false;
        }
        try {
            app(CustomerPortalScope::class)->authorizeManager($this->actor(), $this->customerId, $ability);

            return true;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    private function views(): array
    {
        $views = self::availableViews($this->actor());
        if ($this->customerId && ! $this->portalAllowed('customers.portal.manage')) {
            unset($views['portal']);
        }

        return $views;
    }

    private function sections(): array
    {
        if (! $this->portalAllowed('customers.portal.manage')) {
            return [];
        }

        return array_filter(self::SECTIONS, fn ($label, $section) => match ($section) {
            'automation' => $this->portalAllowed('customers.portal.automation'),
            'publications' => $this->portalAllowed('customers.portal.publish'),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function selectCustomer(int $id): void
    {
        $this->customerId = (int) $this->customers()->findOrFail($id)->id;
        $this->startCreating = false;
        $this->editingCustomerId = null;
        $views = $this->views();
        if (! isset($views[$this->view])) {
            $this->view = array_key_first($views) ?? '';
        }
        if ($this->view === 'portal' && ! isset($this->sections()[$this->section])) {
            $this->section = 'access';
        }
        $this->contextRevision++;
        $this->resetValidation();
        $this->syncUrl();
    }

    public function setView(string $view): void
    {
        abort_unless($this->customerId, 403);
        abort_unless(isset($this->views()[$view]), 403);
        $this->view = $view;
        $this->startCreating = false;
        $this->editingCustomerId = null;
        if ($view === 'portal' && ! isset($this->sections()[$this->section])) {
            $this->section = 'access';
        }
        $this->contextRevision++;
        $this->resetValidation();
        $this->syncUrl();
    }

    public function setSection(string $section): void
    {
        abort_unless($this->view === 'portal' && isset($this->sections()[$section]), 403);
        $this->section = $section;
        $this->contextRevision++;
        $this->resetValidation();
        $this->syncUrl();
    }

    public function createCustomer(): void
    {
        abort_unless($this->actor()->can('operations.manage'), 403);
        $this->startCreating = true;
        $this->editingCustomerId = null;
        $this->contextRevision++;
    }

    public function editCustomer(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->editingCustomerId = (int) $this->customers()->findOrFail($id)->id;
        $this->startCreating = false;
        $this->contextRevision++;
    }

    #[On('customer-form-closed')]
    public function customerFormClosed(?int $workspaceRevision = null): void
    {
        if ($workspaceRevision === $this->contextRevision) {
            $this->closeCustomerForm();
        }
    }

    public function closeCustomerForm(): void
    {
        $this->actor();
        $this->editingCustomerId = null;
        $this->startCreating = false;
        $this->contextRevision++;
    }

    public function showList(): void
    {
        abort_unless(self::availableViews($this->actor()), 403);
        $this->customerId = null;
        $this->closeCustomerForm();
        $this->resetValidation();
        $this->syncUrl();
    }

    public function toggleCustomerSelection(int $id): void
    {
        $id = (int) $this->customers()->findOrFail($id)->id;
        $this->selectedListCustomerId = $this->selectedListCustomerId === $id ? null : $id;
    }

    #[On('customer-record-saved')]
    public function customerSaved(int $customerId, ?int $workspaceRevision = null): void
    {
        abort_unless($this->actor()->can('operations.manage'), 403);
        if ($workspaceRevision !== $this->contextRevision) {
            return;
        }
        $this->customers()->findOrFail($customerId);
        if ($this->editingCustomerId && ! $this->customerId) {
            $this->closeCustomerForm();

            return;
        }
        $this->selectCustomer($customerId);
    }

    private function canManage(): bool
    {
        return $this->actor()->can('operations.manage');
    }

    private function listInteger(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value) && ctype_digit($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 422);

        return (int) $value;
    }

    private function validateListState(): void
    {
        abort_unless(mb_strlen($this->search) <= 100 && in_array($this->activeFilter, ['all', 'active', 'inactive'], true)
            && in_array($this->perPage, [15, 30, 50, 100], true)
            && in_array($this->sortDir, ['asc', 'desc'], true), 422);
        $canManage = $this->canManage();
        abort_unless(in_array($this->sortBy, $canManage ? ['company_name', 'city', 'is_active'] : ['company_name'], true), 403);
        abort_unless($canManage || $this->activeFilter === 'all', 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'activeFilter', 'perPage'], true)) {
            $this->validateListState();
            $this->selectedListCustomerId = null;
            $this->resetPage('customersPage');
            $this->syncUrl();
        }
    }

    public function updatedPaginators(int $page, string $pageName): void
    {
        abort_unless($pageName === 'customersPage' && $page > 0, 422);
        $this->syncUrl();
    }

    public function sort(string $key, string $direction): void
    {
        $this->sortBy = $key;
        $this->sortDir = $direction;
        $this->validateListState();
        $this->resetPage('customersPage');
        $this->syncUrl();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->activeFilter = $this->canManage() ? 'active' : 'all';
        $this->selectedListCustomerId = null;
        $this->resetPage('customersPage');
        $this->syncUrl();
    }

    private function indexQuery(): Builder
    {
        $query = $this->customers();
        $canManage = $this->canManage();
        if ($canManage) {
            $query->addSelect(['customer_number', 'contact_name', 'email', 'phone', 'city', 'is_active']);
        }
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $query) use ($term, $canManage): void {
                $query->where('company_name', 'like', $term);
                if ($canManage) {
                    foreach (['customer_number', 'contact_name', 'email', 'phone', 'city'] as $column) {
                        $query->orWhere($column, 'like', $term);
                    }
                }
            });
        }
        if ($canManage && $this->activeFilter !== 'all') {
            $query->where('is_active', $this->activeFilter === 'active');
        }

        return $query->orderBy($this->sortBy, $this->sortDir)->orderBy('id');
    }

    private function syncUrl(): void
    {
        if (! Route::has('operations.page')) {
            return;
        }
        $url = route('operations.page', array_filter([
            'page' => 'customers', 'view' => $this->view,
            'section' => $this->customerId && $this->view === 'portal' ? $this->section : null,
            'customer' => $this->customerId, 'search' => $this->search,
            'status' => $this->activeFilter !== ($this->canManage() ? 'active' : 'all') ? $this->activeFilter : null,
            'per_page' => $this->perPage !== 15 ? $this->perPage : null,
            'sort' => $this->sortBy !== 'company_name' ? $this->sortBy : null,
            'direction' => $this->sortDir !== 'asc' ? $this->sortDir : null,
            'customersPage' => ($this->paginators['customersPage'] ?? 1) > 1 ? $this->paginators['customersPage'] : null,
        ], fn ($value) => $value !== null && $value !== ''));
        $this->dispatch('rt-workspace-url', url: $url);
    }

    public function render()
    {
        abort_unless(self::availableViews($this->actor()), 403);
        $this->validateListState();
        $customers = $this->customers();
        $customer = $this->customerId ? (clone $customers)->findOrFail($this->customerId) : null;
        $views = $this->views();
        abort_unless($this->view === '' || isset($views[$this->view]), 403);
        if ($this->customerId && $this->view === 'portal') {
            abort_unless(isset($this->sections()[$this->section]), 403);
        }

        return view('livewire.operations.customer-workspace', [
            'customers' => $this->customerId ? null : $this->indexQuery()->paginate($this->perPage, ['*'], 'customersPage'),
            'customer' => $customer,
            'views' => $views,
            'sections' => $this->view === 'portal' ? $this->sections() : [],
            'canCreate' => $this->actor()->can('operations.manage'),
            'activeFilterCount' => (trim($this->search) !== '' ? 1 : 0) + ($this->activeFilter !== 'all' && $this->canManage() ? 1 : 0),
        ]);
    }
}
