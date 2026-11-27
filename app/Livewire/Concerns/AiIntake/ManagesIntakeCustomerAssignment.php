<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Models\AiIntake;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\On;

/**
 * Kunden- und Kontaktzuordnung eines Eingangs samt Kundenentwurf.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakeCustomerAssignment
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function selected(): \App\Models\AiIntake;
    abstract private function perform(callable $action, bool $reload = true): void;

    public function updatedAssignCustomerId(): void
    {
        $this->assignContactId = '';
    }

    public function editCustomerAssignment(): void
    {
        $this->perform(function (): void {
            $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null,
                409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            $this->detailTab = 'review';
            $this->customerEditorOpen = true;
        }, false);
    }

    public function cancelCustomerAssignment(): void
    {
        $record = $this->selected();
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->customerEditorOpen = false;
        $this->resetValidation();
    }

    public function assignCustomer(): void
    {
        $this->actor();
        $this->validate(['assignCustomerId' => ['required', 'integer', 'min:1'], 'assignContactId' => ['nullable', 'integer', 'min:1']]);
        abort_if($this->customerId && (int) $this->assignCustomerId !== $this->customerId, 403);
        $this->perform(function (): void {
            abort_if($this->editingProposalId || $this->manualEditorOpen, 409, 'Die offene Position zuerst speichern oder verwerfen.');
            if (! $this->embedded) {
                $this->claimManual();
            }
            app(AiIntakeService::class)->assignCustomer($this->selected(), $this->actor(), (int) $this->assignCustomerId, $this->assignContactId === '' ? null : (int) $this->assignContactId, $this->selectedRevision);
        });
    }

    private function hasUnsavedAssignment(AiIntake $intake): bool
    {
        return $this->assignCustomerId !== (string) ($intake->customer_id ?? '')
            || $this->assignContactId !== (string) ($intake->customer_contact_id ?? '');
    }

    public function prepareCustomer(): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        abort_unless(! $this->customerId && ! $this->selected()->customer_id && $this->selected()->revision === $this->selectedRevision, 409);
        $this->detailOpen = false;
        $this->customerDraftOpen = true;
    }

    #[On('customer-record-saved')]
    public function customerCreated(int $customerId, ?int $workspaceRevision = null): void
    {
        if (! $this->customerDraftOpen || $workspaceRevision !== $this->selectedRevision) {
            return;
        }
        $record = $this->selected();
        abort_unless($record->customer_id === $customerId, 409);
        $this->customerDraftOpen = false;
        $this->select($record->id);
    }

    #[On('customer-form-closed')]
    public function customerDraftClosed(?int $workspaceRevision = null): void
    {
        if (! $this->customerDraftOpen || $workspaceRevision !== $this->selectedRevision) {
            return;
        }
        $this->customerDraftOpen = false;
        $this->select($this->selected()->id);
    }
}
