<?php

namespace App\Livewire\Operations;

use App\Models\CustomerContact;
use App\Models\InquiryFollowUp;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\CustomerWorkflowService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InquiryProcessPanel extends Component
{
    #[Locked]
    public int $inquiryId;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?int $followUpId = null;

    #[Locked]
    public ?int $followUpRevision = null;

    public bool $processOpen = false;

    public bool $followUpOpen = false;

    public bool $completeOpen = false;

    public array $form = [];

    public array $followUp = [];

    public string $completion = '';

    public function mount(int $inquiryId): void
    {
        $this->inquiryId = $inquiryId;
        $this->inquiry();
    }

    private function inquiry(): OperationInquiry
    {
        OperationsAccess::authorize(auth()->user(), 'operations.inquiries.manage');
        abort_unless(CustomerWorkflowService::ready(), 503);

        return OperationInquiry::findOrFail($this->inquiryId);
    }

    public function editProcess(): void
    {
        $inquiry = $this->inquiry();
        $detail = $inquiry->processDetail;
        $this->revision = $detail?->revision;
        $this->form = ['priority' => $detail?->priority ?? 'normal', 'assignee_id' => $detail?->assignee_id ?? '',
            'customer_contact_id' => $detail?->customer_contact_id ?? '', 'timezone' => $inquiry->timezone,
            'due_at' => $detail?->due_at?->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i') ?? ''];
        $this->processOpen = true;
        $this->resetValidation();
    }

    public function saveProcess(CustomerWorkflowService $service): void
    {
        $service->process($this->inquiry(), $this->revision, $this->form, auth()->user());
        $this->processOpen = false;
    }

    public function createFollowUp(): void
    {
        $inquiry = $this->inquiry();
        $this->followUp = ['title' => '', 'kind' => 'check', 'note' => '', 'due_at' => '', 'timezone' => $inquiry->timezone, 'assignee_id' => auth()->id()];
        $this->followUpOpen = true;
        $this->resetValidation();
    }

    public function saveFollowUp(CustomerWorkflowService $service): void
    {
        $service->followUp($this->inquiry(), $this->followUp, auth()->user());
        $this->followUpOpen = false;
    }

    public function selectFollowUp(int $id): void
    {
        $this->inquiry();
        $record = InquiryFollowUp::where('operation_inquiry_id', $this->inquiryId)->findOrFail($id);
        $this->followUpId = $id;
        $this->followUpRevision = $record->revision;
        $this->completion = '';
        $this->completeOpen = true;
    }

    public function completeFollowUp(CustomerWorkflowService $service): void
    {
        $this->inquiry();
        InquiryFollowUp::where('operation_inquiry_id', $this->inquiryId)->findOrFail($this->followUpId);
        $service->completeFollowUp($this->followUpId, $this->followUpRevision, $this->completion, auth()->user());
        $this->completeOpen = false;
    }

    public function render()
    {
        $inquiry = $this->inquiry();

        return view('livewire.operations.inquiry-process-panel', [
            'detail' => $inquiry->processDetail, 'followUps' => $inquiry->followUps()->with('assignee')->limit(100)->get(),
            'contacts' => CustomerContact::where('customer_id', $inquiry->customer_id)->where('is_active', true)->orderBy('name')->get(),
            'assignees' => User::where('status', true)->orderBy('name')->get()->filter(fn ($user) => Gate::forUser($user)->allows('operations.inquiries.manage')),
        ]);
    }
}
