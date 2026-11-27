<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Models\AiIntake;
use App\Models\Shift;
use App\Support\Operations\AiDispositionSettings;
use Livewire\Attributes\On;

/**
 * Detail eines Eingangs: Auswahl, Reiter, Leistungswahl und Wechsel zum Assistenten.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakeDetail
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function scope(): \Illuminate\Database\Eloquent\Builder;
    abstract private function selected(): \App\Models\AiIntake;
    abstract private function perform(callable $action, bool $reload = true): void;

    public function select(int $id): void
    {
        $record = $this->scope()->findOrFail($id);
        $previousProposalId = $this->selectedId === $id ? $this->drawerProposalId : null;
        $this->selectedId = $id;
        $currentProposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->sortBy('position_index');
        $this->drawerProposalId = $currentProposals->firstWhere('id', $previousProposalId)?->id ?? $currentProposals->first()?->id;
        $this->selectedRevision = $record->revision;
        $this->selectedSettingsRevision = (int) AiDispositionSettings::all(true)['revision'];
        $this->selectedSourceRevision = $record->source_revision;
        $this->confirmationProposalRevisions = $record->proposals()->where('source_revision', $record->source_revision)->pluck('revision', 'id')->all();
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->editingProposalId = null;
        $this->proposalForm = [];
        $this->manualEditorOpen = false;
        $this->customerEditorOpen = false;
        $this->mappingInquiryIds = [];
        $this->reset('planningProposalId', 'planningProposalRevision', 'planningSegments', 'planningShiftRevisions');
        foreach ($record->proposals as $proposal) {
            $this->planningShiftRevisions[$proposal->id] = Shift::whereIn('id', $proposal->applied_shift_ids ?? [])->pluck('revision', 'id')->all();
        }
        $this->detailOpen = true;
        $this->detailTab = 'review';
        if ($this->embedded) {
            $this->captureOpen = false;
        }
        $this->resetValidation();
        $this->dispatch('ai-intake-selected', intakeId: $id, customerId: $this->customerId, componentId: $this->getId(),
            intakeRevision: $record->revision, sourceRevision: $record->source_revision);
        if ($this->embedded) {
            $this->dispatch('select-ai-intake-assistant', intakeId: $id, componentId: $this->getId());
        }
    }

    #[On('open-ai-intake')]
    public function open(?int $intakeId = null, bool $create = false, ?string $parentComponentId = null): void
    {
        if (! $this->embedded || ($parentComponentId !== null && $parentComponentId !== $this->parentComponentId)) {
            return;
        }
        $this->actor();
        if ($intakeId) {
            $this->select($intakeId);
        } elseif ($create) {
            $this->openCapture();
        } else {
            $this->closeDetail();
        }
    }

    public function closeDetail(): void
    {
        $this->actor();
        $this->detailOpen = false;
        $this->selectedId = $this->editingProposalId = null;
        $this->drawerProposalId = null;
        $this->selectedRevision = $this->selectedSettingsRevision = $this->proposalRevision = 0;
        $this->selectedSourceRevision = 0;
        $this->confirmationProposalRevisions = [];
        $this->proposalForm = $this->mappingInquiryIds = [];
        $this->manualEditorOpen = $this->customerEditorOpen = false;
        $this->assignCustomerId = $this->assignContactId = '';
        $this->detailTab = 'review';
        $this->resetValidation();
        if ($this->embedded) {
            $this->dispatch('ai-intake-assistant-overview', customerId: $this->customerId, componentId: $this->getId());
        }
    }

    public function setDetailTab(string $tab): void
    {
        $this->selected();
        abort_unless(in_array($tab, $this->embedded ? ['review', 'sources', 'communication', 'assistant'] : ['review', 'sources', 'communication', 'details'], true), 422);
        $this->detailTab = $tab;
        if ($tab === 'assistant' && $this->embedded) {
            $this->dispatch('ai-intake-discussion-open', componentId: $this->getId());
        }
    }

    /** Change the footer's position context without discarding another editor. */
    public function selectDrawerPosition(int $id): void
    {
        $this->perform(function () use ($id): void {
            $record = $this->selected();
            $this->assertDrawerReady($record);
            $proposal = $record->proposals()->findOrFail($id);
            abort_unless($proposal->source_revision === $record->source_revision && $proposal->status !== 'stale'
                && ($this->confirmationProposalRevisions[$id] ?? null) === $proposal->revision, 409, 'Die Leistung wurde geändert. Bitte neu laden.');
            $this->drawerProposalId = $id;
            $this->detailTab = 'review';
        }, false);
    }

    private function assertDrawerReady(AiIntake $record): void
    {
        abort_if($this->embedded, 409);
        abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null
            || $this->customerEditorOpen || $this->hasUnsavedAssignment($record), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
        abort_unless($record->revision === $this->selectedRevision && $record->source_revision === $this->selectedSourceRevision
            && $this->selectedSettingsRevision === (int) AiDispositionSettings::all(true)['revision'], 409, 'Der Eingang wurde inzwischen geändert. Bitte neu laden.');
    }

    public function discuss(int $id): void
    {
        if (! $this->embedded) {
            $this->perform(function () use ($id): void {
                abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null
                    || ($this->selectedId && $this->hasUnsavedAssignment($this->selected())),
                    409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
                $this->select($id);
                $this->openAssist();
            }, false);

            return;
        }
        $this->select($id);
        $this->detailTab = 'assistant';
        if ($this->embedded) {
            $this->dispatch('ai-intake-discussion-open', componentId: $this->getId());
        }
    }

    #[On('ai-intake-question-answered')]
    public function showAnsweredQuestion(?string $hostComponentId = null, ?int $intakeId = null): void
    {
        if (! $this->embedded || ! $this->detailOpen || $hostComponentId !== $this->getId() || $intakeId !== $this->selectedId) {
            return;
        }
        $this->setDetailTab('assistant');
    }

    public function openAssist(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision, 409, 'Der Eingang wurde inzwischen geändert.');
            $this->detailOpen = false;
            $this->dispatch('open-ai-intake-panel', intakeId: $intake->id, create: false, customerId: $this->customerId ?? $intake->customer_id);
        }, false);
    }

    public function openOutbox(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->manualEditorOpen || $this->editingProposalId || $this->planningProposalId !== null
                || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision,
                409, 'Der Eingang wurde inzwischen geändert.');
            $this->detailOpen = false;
            $this->dispatch('railtime-assistant-open');
            $this->dispatch('operations-assistant-action', action: 'outbox');
        }, false);
    }
}
