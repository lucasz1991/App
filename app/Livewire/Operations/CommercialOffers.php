<?php

namespace App\Livewire\Operations;

use App\Models\CommercialOfferRevision;
use App\Models\CustomerCondition;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Services\Operations\CommercialOfferService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CommercialOffers extends Component
{
    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    #[Locked]
    public ?int $latestId = null;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $version = null;

    public bool $formOpen = false;

    public bool $acceptanceOpen = false;

    public bool $detailOpen = false;

    public array $form = ['valid_until' => '', 'positions' => []];

    public string $note = '';

    public bool $authorized = false;

    #[Locked]
    public bool $showList = true;

    public function mount(string $subjectType, int $subjectId, ?int $initialOfferId = null, bool $showList = true): void
    {
        abort_unless(in_array($subjectType, ['OperationInquiry', 'Order'], true), 404);
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->showList = $showList;
        $this->subject();
        if ($initialOfferId !== null) {
            $this->select($initialOfferId);
        }
    }

    private function subject(): OperationInquiry|Order
    {
        OperationsAccess::authorize(auth()->user(), $this->subjectType === 'Order' ? 'operations.manage' : 'operations.inquiries.manage');
        abort_unless(CommercialOfferService::ready(), 503);

        return $this->subjectType === 'Order' ? Order::findOrFail($this->subjectId) : OperationInquiry::findOrFail($this->subjectId);
    }

    public function create(): void
    {
        $this->subject();
        $latest = $this->query()->latest('revision')->first();
        $this->latestId = $latest?->id;
        $this->form = ['kind' => $this->subjectType === 'Order' ? 'amendment' : 'offer', 'terms' => '', 'reason' => '', 'valid_until' => '',
            'positions' => [['title' => '', 'quantity' => '1', 'unit' => 'Stunde', 'price' => '', 'kind' => 'standard', 'starts_at' => '', 'ends_at' => '', 'timezone' => $this->subject()->timezone]]];
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function addPosition(): void
    {
        $this->subject();
        abort_if(count($this->form['positions'] ?? []) >= 100, 422);
        $this->form['positions'][] = ['title' => '', 'quantity' => '1', 'unit' => 'Stunde', 'price' => '', 'kind' => 'standard', 'starts_at' => '', 'ends_at' => '', 'timezone' => $this->subject()->timezone];
    }

    public function removePosition(int $index): void
    {
        $this->subject();
        unset($this->form['positions'][$index]);
        $this->form['positions'] = array_values($this->form['positions']);
    }

    public function updatedForm($value, string $key): void
    {
        if (preg_match('/^positions\.(\d+)\.condition_id$/', $key, $match) && filled($value)) {
            $subject = $this->subject();
            $condition = $this->conditions($subject)->findOrFail((int) $value);
            $index = (int) $match[1];
            abort_unless(isset($this->form['positions'][$index]), 422);
            $this->form['positions'][$index]['title'] = $condition->label;
            $this->form['positions'][$index]['unit'] = $condition->unit;
            $this->form['positions'][$index]['price'] = number_format($condition->unit_price_cents / 100, 2, '.', '');
        }
    }

    private function conditions(OperationInquiry|Order $subject)
    {
        $date = $subject->starts_at?->format('Y-m-d') ?? now($subject->timezone)->format('Y-m-d');

        return CustomerCondition::where('customer_id', $subject->customer_id)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date));
    }

    public function save(CommercialOfferService $service): void
    {
        $service->draft($this->subject(), $this->latestId, $this->form, auth()->user());
        $this->formOpen = false;
    }

    public function select(int $id): void
    {
        $this->subject();
        $record = $this->query()->findOrFail($id);
        $this->selectedId = $id;
        $this->version = $record->state_version;
        $this->detailOpen = true;
        $this->reset(['note', 'authorized']);
    }

    public function issue(CommercialOfferService $service): void
    {
        $this->subject();
        $this->query()->findOrFail($this->selectedId);
        $service->issue($this->selectedId, $this->version, auth()->user());
        $this->select($this->selectedId);
        $this->dispatch('commercial-offer-updated', inquiryId: $this->subjectType === 'OperationInquiry' ? $this->subjectId : null);
    }

    public function accept(CommercialOfferService $service): void
    {
        $this->subject();
        $this->query()->findOrFail($this->selectedId);
        $service->accept($this->selectedId, $this->version, $this->note, $this->authorized, auth()->user());
        $this->acceptanceOpen = false;
        $this->select($this->selectedId);
        $this->dispatch('commercial-offer-updated', inquiryId: $this->subjectType === 'OperationInquiry' ? $this->subjectId : null);
    }

    private function query()
    {
        return CommercialOfferRevision::where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId);
    }

    public function render()
    {
        $subject = $this->subject();

        return view('livewire.operations.commercial-offers', [
            'subject' => $subject, 'offers' => $this->query()->latest('revision')->limit(100)->get(),
            'selected' => $this->selectedId ? $this->query()->find($this->selectedId) : null,
            'canCreate' => ! ($subject instanceof OperationInquiry) || (! $subject->order_id && ! $subject->duplicate_of_id && $subject->status !== 'rejected'),
            'conditions' => Schema::hasTable('customer_conditions') ? $this->conditions($subject)->orderBy('label')->get() : collect(),
        ]);
    }
}
