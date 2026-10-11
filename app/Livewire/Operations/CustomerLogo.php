<?php

namespace App\Livewire\Operations;

use App\Models\CustomerPortalIdentity;
use App\Models\User;
use App\Services\Operations\CustomerLogoService;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerLogo extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $customerId;

    #[Locked]
    public bool $portal = false;

    #[Locked]
    public ?string $expectedPath = null;

    public $logoFile;

    public function mount(int $customerId, bool $portal = false): void
    {
        $this->customerId = $customerId;
        $this->portal = $portal;
        $this->expectedPath = app(CustomerLogoService::class)->customer($this->actor(), $customerId)->logo_path;
    }

    private function actor(): User|CustomerPortalIdentity
    {
        $actor = Auth::guard($this->portal ? 'customer_portal' : 'web')->user();
        abort_unless($this->portal ? $actor instanceof CustomerPortalIdentity : $actor instanceof User, 403);

        return $actor;
    }

    public function updatedLogoFile(): void
    {
        app(CustomerLogoService::class)->customer($this->actor(), $this->customerId, true);
        $this->validate(['logoFile' => CustomerLogoService::rules()], [], ['logoFile' => 'Firmenlogo']);
    }

    public function save(): void
    {
        app(CustomerLogoService::class)->customer($this->actor(), $this->customerId, true);
        $this->validate(['logoFile' => CustomerLogoService::rules()], [], ['logoFile' => 'Firmenlogo']);
        try {
            $customer = app(CustomerLogoService::class)->save($this->actor(), $this->customerId, $this->logoFile, $this->expectedPath);
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [409, 503], true)) {
                throw $exception;
            }
            $this->addError('logoFile', $exception->getMessage());

            return;
        }
        $this->expectedPath = $customer->logo_path;
        $this->reset('logoFile');
        $this->resetValidation();
        $this->dispatch('customer-logo-saved', customerId: $this->customerId);
        $this->dispatch('swal:toast', type: 'success', text: 'Firmenlogo gespeichert.');
    }

    public function remove(): void
    {
        try {
            app(CustomerLogoService::class)->remove($this->actor(), $this->customerId, $this->expectedPath);
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [409, 503], true)) {
                throw $exception;
            }
            $this->addError('logoFile', $exception->getMessage());

            return;
        }
        $this->expectedPath = null;
        $this->reset('logoFile');
        $this->resetValidation();
        $this->dispatch('customer-logo-saved', customerId: $this->customerId);
        $this->dispatch('swal:toast', type: 'success', text: 'Firmenlogo entfernt.');
    }

    public function reload(): void
    {
        $this->expectedPath = app(CustomerLogoService::class)->customer($this->actor(), $this->customerId)->logo_path;
        $this->reset('logoFile');
        $this->resetValidation();
    }

    public function render()
    {
        $actor = $this->actor();
        $customer = app(CustomerLogoService::class)->customer($actor, $this->customerId);
        $canEdit = $actor instanceof User ? $actor->fresh()?->can('operations.manage') : false;
        if ($actor instanceof CustomerPortalIdentity) {
            $membership = app(CustomerPortalScope::class)->membership($actor, $this->customerId);
            $canEdit = $membership->role === 'coordinator' && in_array('changes.create', $membership->capabilities ?? [], true) && in_array('changes', $membership->setting?->modules ?? [], true);
        }

        return view('livewire.operations.customer-logo', ['customer' => $customer, 'canEdit' => $canEdit, 'ready' => CustomerLogoService::ready()]);
    }
}
