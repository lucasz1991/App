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
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerWorkspace extends Component
{
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
        $customers = $this->customers();
        $id = $context['customer'] ?? null;
        if ($id !== null && $id !== '') {
            abort_unless((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0), 404);
            $this->customerId = (int) $customers->findOrFail((int) $id)->id;
        } else {
            $this->customerId = $customers->orderBy('company_name')->value('id');
        }
        $views = $this->views();
        if ($initialView !== '') {
            abort_unless(isset(self::VIEWS[$initialView]), 404);
            abort_unless(isset($views[$initialView]), 403);
        }
        $this->view = $initialView ?: (array_key_first($views) ?? '');
        if ($initialSection !== '') {
            abort_unless(isset(self::SECTIONS[$initialSection]), 404);
            if ($this->view === 'portal') {
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
        if (! $this->portalAllowed('customers.portal.manage')) {
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
        abort_unless(isset($this->views()[$view]), 403);
        $this->view = $view;
        $this->startCreating = false;
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
        $this->view = 'master';
        $this->startCreating = true;
        $this->contextRevision++;
    }

    #[On('customer-record-saved')]
    public function customerSaved(int $customerId): void
    {
        abort_unless($this->actor()->can('operations.manage'), 403);
        $this->selectCustomer($customerId);
    }

    private function syncUrl(): void
    {
        if (! Route::has('operations.page')) {
            return;
        }
        $url = route('operations.page', array_filter(['page' => 'customers', 'view' => $this->view, 'section' => $this->view === 'portal' ? $this->section : null, 'customer' => $this->customerId]));
        $this->dispatch('rt-workspace-url', url: $url);
    }

    public function render()
    {
        $customers = $this->customers();
        $customer = $this->customerId ? (clone $customers)->findOrFail($this->customerId) : null;
        $views = $this->views();
        abort_unless($this->view === '' || isset($views[$this->view]), 403);
        if ($this->view === 'portal') {
            abort_unless(isset($this->sections()[$this->section]), 403);
        }

        return view('livewire.operations.customer-workspace', [
            'customers' => $customers->orderBy('company_name')->get(),
            'customer' => $customer,
            'views' => $views,
            'sections' => $this->view === 'portal' ? $this->sections() : [],
            'canCreate' => $this->actor()->can('operations.manage'),
        ]);
    }
}
