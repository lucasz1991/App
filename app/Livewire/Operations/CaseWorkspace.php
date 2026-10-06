<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerCapacityReservation;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSubmission;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\OperationWorkflow;
use App\Models\User;
use App\Services\Operations\CommercialOfferService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CaseWorkspace extends Component
{
    #[Locked]
    public string $view = 'inbox';

    #[Locked]
    public string $section = '';

    #[Locked]
    public array $context = [];

    public static function availableViews(User $actor): array
    {
        if (! $actor->status || ! OperationsAccess::ready()) {
            return [];
        }

        return array_filter([
            'inbox' => $actor->can('operations.inquiries.manage') ? ['value' => 'inbox', 'label' => 'Eingang', 'icon' => 'fa-inbox'] : null,
            'offers' => CommercialOfferService::ready() && ($actor->can('operations.inquiries.manage') || $actor->can('operations.manage')) ? ['value' => 'offers', 'label' => 'Angebote', 'icon' => 'fa-file-invoice'] : null,
            'orders' => $actor->can('operations.manage') ? ['value' => 'orders', 'label' => 'Aufträge', 'icon' => 'fa-briefcase'] : null,
        ]);
    }

    public static function availableSections(User $actor): array
    {
        return array_filter([
            'overview' => $actor->can('operations.inquiries.manage') ? 'Anfragen' : null,
            'imports' => $actor->can('operations.inquiries.manage') && OperationsEnhancementsSchema::ready() ? 'Eingangsprüfung' : null,
            'portal' => $actor->can('operations.inquiries.manage') && $actor->can('customers.portal.manage') && CustomerPortalIntakeSchema::ready() && CustomerPortalWorkflowSchema::ready() ? 'Portaleingang' : null,
        ]);
    }

    public function mount(string $initialView = 'inbox', string $initialSection = '', array $context = []): void
    {
        $actor = $this->actor();
        $views = self::availableViews($actor);
        abort_unless($views, 403);
        abort_unless(in_array($initialView, ['inbox', 'offers', 'orders'], true), 404);
        if ($initialView === 'inbox' && ! isset($views[$initialView]) && ! request()->has('view') && $context === []) {
            $initialView = array_key_first($views);
        }
        abort_unless(isset($views[$initialView]), 403);
        $this->view = $initialView;
        $this->section = $initialSection ?: 'overview';
        $this->context = $context;
        if ($this->view === 'inbox' && $this->section === 'portal' && ! isset($this->context['customer']) && isset(self::availableSections($actor)['portal'])) {
            $customerId = app(CustomerPortalScope::class)->manageableCustomers($actor)->orderBy('company_name')->value('id');
            if ($customerId) {
                $this->context['customer'] = (int) $customerId;
            }
        }
        $this->validateContext();
        $this->access();
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && $actor->status, 403);

        return $actor;
    }

    private function access(): void
    {
        $actor = $this->actor();
        abort_unless(isset(self::availableViews($actor)[$this->view]), 403);
        if ($this->view === 'inbox') {
            abort_unless(isset(self::availableSections($actor)[$this->section]), 403);
        } elseif ($this->view === 'orders') {
            abort_unless(in_array($this->section, ['overview', 'planning', 'proofs', 'costs', 'history'], true), 404);
            if ($this->section === 'costs') {
                OperationsAccess::authorize($actor, 'operations.costs.manage');
            }
        } else {
            abort_unless($this->section === 'overview', 404);
        }
        $this->validateContext();
    }

    private function validateContext(): void
    {
        foreach (['inquiry', 'order', 'customer', 'user', 'shift', 'revision', 'record'] as $key) {
            if (isset($this->context[$key])) {
                abort_unless(is_int($this->context[$key]) && $this->context[$key] > 0, 404);
            }
        }
        $customer = isset($this->context['customer']) ? Customer::findOrFail($this->context['customer']) : null;
        $inquiry = isset($this->context['inquiry']) ? OperationInquiry::findOrFail($this->context['inquiry']) : null;
        $order = isset($this->context['order']) ? Order::findOrFail($this->context['order']) : null;
        abort_if($customer && (($inquiry && $inquiry->customer_id !== $customer->id) || ($order && $order->customer_id !== $customer->id)), 404);
        abort_if($inquiry && $order && $inquiry->order_id !== $order->id, 404);
        if (($this->context['record_type'] ?? '') === 'proof') {
            abort_unless($this->view === 'orders' && $this->section === 'proofs' && $order, 404);
            $proof = OperationWorkflow::where('kind', 'proof')->where('order_id', $order->id)->findOrFail($this->context['record'] ?? 0);
            abort_if(isset($this->context['revision']) && $proof->revision !== $this->context['revision'], 409, 'Leistungsnachweis wurde geändert.');
        }
        if ($this->view === 'inbox' && $this->section === 'overview' && $inquiry) {
            OperationsAccess::authorize($this->actor(), 'operations.inquiries.manage');
            abort_if(isset($this->context['revision']) && $inquiry->revision !== $this->context['revision'], 409, 'Vorgangsstand wurde geändert.');
        }
        if (($this->context['source'] ?? '') === 'reservation') {
            $reservation = CustomerCapacityReservation::findOrFail($this->context['record'] ?? 0);
            app(CustomerPortalScope::class)->authorizeManager($this->actor(), $reservation->customer_id);
            abort_unless($customer && $customer->id === $reservation->customer_id, 404);
            abort_if($order && $reservation->order_id !== $order->id, 404);
            abort_if($inquiry && $reservation->inquiry_id !== $inquiry->id, 404);
            abort_if(isset($this->context['revision']), 404);
        }
        if ($this->view === 'inbox' && $this->section === 'portal' && $customer) {
            app(CustomerPortalScope::class)->authorizeManager($this->actor(), $customer->id);
            $source = $this->context['source'] ?? '';
            abort_unless(in_array($source, ['', 'submission', 'request', 'reservation'], true), 404);
            if (in_array($source, ['submission', 'request'], true)) {
                $record = ($source === 'submission' ? CustomerPortalSubmission::class : CustomerPortalRequest::class)::where('customer_id', $customer->id)->findOrFail($this->context['record'] ?? 0);
                abort_if(isset($this->context['revision']) && $record->revision !== $this->context['revision'], 409, 'Portalvorgang wurde geändert.');
            }
        }
    }

    public function setView(string $view): void
    {
        $this->access();
        abort_unless(isset(self::availableViews($this->actor())[$view]), 403);
        $this->redirectRoute('operations.page', array_filter(['page' => 'cases', 'view' => $view, 'customer' => $this->context['customer'] ?? null]), navigate: true);
    }

    public function setSection(string $section): void
    {
        $this->access();
        abort_unless($this->view === 'inbox' && isset(self::availableSections($this->actor())[$section]), 403);
        $this->redirectRoute('operations.page', array_filter(['page' => 'cases', 'view' => 'inbox', 'section' => $section, 'customer' => $this->context['customer'] ?? null]), navigate: true);
    }

    public function setPortalCustomer(int $customerId): void
    {
        $this->access();
        abort_unless($this->view === 'inbox' && $this->section === 'portal', 403);
        app(CustomerPortalScope::class)->manageableCustomers($this->actor())->findOrFail($customerId);
        $this->redirectRoute('operations.page', ['page' => 'cases', 'view' => 'inbox', 'section' => 'portal', 'customer' => $customerId], navigate: true);
    }

    public function render()
    {
        $this->access();

        return view('livewire.operations.case-workspace', [
            'views' => self::availableViews($this->actor()), 'sections' => self::availableSections($this->actor()),
            'portalCustomers' => $this->view === 'inbox' && $this->section === 'portal' ? app(CustomerPortalScope::class)->manageableCustomers($this->actor())->orderBy('company_name')->get(['id', 'company_name']) : collect(),
            'reservation' => ($this->context['source'] ?? '') === 'reservation' ? CustomerCapacityReservation::findOrFail($this->context['record']) : null,
        ]);
    }
}
