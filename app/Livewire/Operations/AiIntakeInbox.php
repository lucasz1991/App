<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiIntakeInbox extends Component
{
    use WithFileUploads, WithPagination;

    public const LABELS = ['received'=>'Erfasst','analyzing'=>'Wird analysiert','review'=>'Prüfung nötig','waiting_customer'=>'Antwort ausstehend','ready'=>'Vorbereitet','paused'=>'Pausiert','failed'=>'Verarbeitung prüfen','completed'=>'Übernommen'];
    #[Locked]
    public ?int $customerId = null;
    #[Locked]
    public ?int $selectedId = null;
    #[Locked]
    public int $selectedRevision = 0;
    #[Locked]
    public ?int $editingProposalId = null;
    #[Locked]
    public int $proposalRevision = 0;
    public bool $detailOpen = false;
    public bool $captureOpen = false;
    public string $search = '';
    public string $statusFilter = 'all';
    public string $text = '';
    public string $contactEmail = '';
    public array $uploads = [];
    public $audioUpload = null;
    public string $assignCustomerId = '';
    public string $assignContactId = '';
    public array $proposalForm = [];

    public function mount(?int $customerId = null, ?int $initialIntakeId = null): void
    {
        $this->actor();
        if ($customerId) Customer::findOrFail($customerId);
        $this->customerId = $customerId;
        if ($initialIntakeId) $this->select($initialIntakeId);
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();
        return $actor;
    }

    private function scope(): Builder
    {
        $this->actor();
        AiIntakeSchema::requireReady();
        return AiIntake::query()->when($this->customerId, fn (Builder $query) => $query->where('customer_id', $this->customerId));
    }

    private function selected(): AiIntake
    {
        abort_unless($this->selectedId, 404);
        return $this->scope()->findOrFail($this->selectedId);
    }

    public function select(int $id): void
    {
        $record = $this->scope()->findOrFail($id);
        $this->selectedId = $id;
        $this->selectedRevision = $record->revision;
        $this->assignCustomerId = (string) ($record->customer_id ?? '');
        $this->assignContactId = (string) ($record->customer_contact_id ?? '');
        $this->editingProposalId = null;
        $this->proposalForm = [];
        $this->detailOpen = true;
        $this->resetValidation();
    }

    public function updatedSearch(): void { $this->resetPage('aiIntakePage'); }
    public function updatedStatusFilter(): void { $this->resetPage('aiIntakePage'); }
    public function updatedAssignCustomerId(): void { $this->assignContactId = ''; }

    public function openCapture(): void
    {
        $this->actor();
        AiIntakeSchema::requireReady();
        $this->captureOpen = true;
    }

    public function removeAudio(): void
    {
        $this->actor();
        if ($this->audioUpload && method_exists($this->audioUpload, 'delete')) $this->audioUpload->delete();
        $this->audioUpload = null;
        $this->dispatch('ai-intake-submitted');
    }

    public function submit(): void
    {
        $actor = $this->actor();
        AiIntakeSchema::requireReady();
        $this->validate(['text'=>['nullable','string','max:20000'],'contactEmail'=>['nullable','email','max:254'],'uploads'=>['array','max:3'],'uploads.*'=>['file','max:10240'],'audioUpload'=>['nullable','file','max:8192']]);
        $files = array_values(array_filter([...$this->uploads, $this->audioUpload]));
        if (trim($this->text) === '' && $files === []) {
            $this->addError('text', 'Text eingeben oder eine Datei beziehungsweise Sprachaufnahme hinzufügen.');
            return;
        }
        $this->perform(function () use ($actor, $files): void {
            $record = app(AiIntakeService::class)->submit($actor, $this->text, $files, ['contact_email'=>$this->contactEmail, 'customer_id'=>$this->customerId, 'timezone'=>'Europe/Berlin']);
            foreach ($files as $file) if (method_exists($file, 'delete')) $file->delete();
            $this->reset('text','contactEmail','uploads','audioUpload','captureOpen');
            $this->dispatch('ai-intake-submitted');
            $this->select($record->id);
        });
    }

    public function assignCustomer(): void
    {
        $this->actor();
        $this->validate(['assignCustomerId'=>['required','integer','min:1'],'assignContactId'=>['nullable','integer','min:1']]);
        abort_if($this->customerId && (int) $this->assignCustomerId !== $this->customerId, 403);
        $this->perform(fn () => app(AiIntakeService::class)->assignCustomer($this->selected(), $this->actor(), (int) $this->assignCustomerId, $this->assignContactId === '' ? null : (int) $this->assignContactId, $this->selectedRevision));
    }

    public function reanalyze(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->reanalyze($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function pause(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->pause($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function createInquiries(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->createInquiries($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function editProposal(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        $this->editingProposalId = $proposal->id;
        $this->proposalRevision = $proposal->revision;
        $this->proposalForm = array_intersect_key($proposal->payload ?? [], array_flip(['demand','segments']));
        $this->resetValidation();
    }

    public function addSegment(): void
    {
        $this->selected();
        abort_unless($this->editingProposalId && count($this->proposalForm['segments'] ?? []) < 100, 422);
        $this->proposalForm['segments'][] = ['starts_at'=>'','ends_at'=>'','timezone'=>$this->proposalForm['demand']['timezone'] ?? 'Europe/Berlin','planned_break_minutes'=>0,'required_staff'=>1];
    }

    public function removeSegment(int $index): void
    {
        $this->selected();
        unset($this->proposalForm['segments'][$index]);
        $this->proposalForm['segments'] = array_values($this->proposalForm['segments'] ?? []);
    }

    public function saveProposal(): void
    {
        $this->perform(function (): void {
            $proposal = $this->selected()->proposals()->findOrFail($this->editingProposalId);
            $payload = $proposal->payload;
            $payload['demand'] = $this->proposalForm['demand'] ?? [];
            $payload['segments'] = $this->proposalForm['segments'] ?? [];
            app(AiIntakeService::class)->saveProposal($proposal, $this->actor(), $payload, $this->proposalRevision);
            $this->editingProposalId = null;
        });
    }

    public function approveProposal(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(fn () => app(AiIntakeService::class)->approveProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision));
    }

    private function perform(callable $action): void
    {
        $this->actor();
        $this->resetValidation();
        try {
            $action();
            if ($this->selectedId) $this->select($this->selectedId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) $this->addError($field, implode(' ', $messages));
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403,404], true)) throw $exception;
            $this->addError('intake', $exception->getMessage() ?: 'Der Vorgang konnte nicht übernommen werden. Aktuellen Stand prüfen.');
        }
    }

    public function render()
    {
        $actor = $this->actor();
        $ready = AiIntakeSchema::ready();
        abort_unless(in_array($this->statusFilter, ['all', ...AiIntake::STATUSES], true), 422);
        $query = $ready ? $this->scope()->with('customer')->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.mb_substr(trim($this->search),0,100).'%')) : null;
        $selected = $ready && $this->selectedId ? $this->selected()->load(['customer','contact','messages','attachments','runs','deliveries','proposals.inquiry']) : null;
        return view('livewire.operations.ai-intake-inbox', [
            'ready'=>$ready, 'enabled'=>AiDispositionSettings::enabled(), 'labels'=>self::LABELS,
            'intakes'=>$query?->latest('id')->paginate(12, ['*'], 'aiIntakePage'), 'selected'=>$selected,
            'customers'=>Customer::active()->when($this->customerId, fn ($query) => $query->whereKey($this->customerId))->orderBy('company_name')->get(),
            'contacts'=>$this->assignCustomerId !== '' ? CustomerContact::where('customer_id',(int)$this->assignCustomerId)->where('is_active',true)->orderBy('name')->get() : collect(),
            'canApprove'=>$actor->can('operations.manage'),
        ]);
    }
}
