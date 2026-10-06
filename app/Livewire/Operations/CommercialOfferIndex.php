<?php

namespace App\Livewire\Operations;

use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerPortalSubmissionItem;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\CommercialOfferService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class CommercialOfferIndex extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public ?int $inquiryId = null;

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?string $selectedSubjectType = null;

    #[Locked]
    public ?int $selectedSubjectId = null;

    #[Locked]
    public int $detailGeneration = 0;

    public string $search = '';

    public string $status = 'all';

    public function mount(?int $customerId = null, ?int $inquiryId = null, ?int $orderId = null, ?int $initialRevision = null): void
    {
        $this->customerId = $customerId;
        $this->inquiryId = $inquiryId;
        $this->orderId = $orderId;
        abort_if($inquiryId && $orderId, 404, 'Angebotsbezug eindeutig auswählen.');
        $this->access();
        if ($initialRevision !== null) {
            abort_unless($initialRevision > 0 && ($inquiryId || $orderId), 404);
            $record = $this->query(false)->where('revision', $initialRevision)->firstOrFail();
            $this->select($record->id);
        }
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
        abort_unless($actor->can('operations.inquiries.manage') || $actor->can('operations.manage'), 403);
        abort_unless(CommercialOfferService::ready(), 503);
        foreach ([$this->customerId, $this->inquiryId, $this->orderId] as $id) {
            abort_if($id !== null && $id < 1, 404);
        }
        if ($this->customerId) {
            Customer::findOrFail($this->customerId);
        }
        if ($this->inquiryId) {
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            $inquiry = OperationInquiry::findOrFail($this->inquiryId);
            abort_if($this->customerId && $inquiry->customer_id !== $this->customerId, 404);
            abort_if($this->orderId && $inquiry->order_id !== $this->orderId, 404);
        }
        if ($this->orderId) {
            OperationsAccess::authorize($actor, 'operations.manage');
            $order = Order::findOrFail($this->orderId);
            abort_if($this->customerId && $order->customer_id !== $this->customerId, 404);
        }
    }

    private function query(bool $latestOnly = true): Builder
    {
        $actor = $this->actor();
        $query = CommercialOfferRevision::query()->where(function (Builder $query) use ($actor, $latestOnly): void {
            $query->whereRaw('1 = 0');
            if ($actor->can('operations.inquiries.manage') && ! $this->orderId) {
                $query->orWhere(fn (Builder $query) => $query->where('subject_type', 'OperationInquiry')->whereExists(function ($parent): void {
                    $parent->selectRaw('1')->from('operation_inquiries')->whereColumn('operation_inquiries.id', 'commercial_offer_revisions.subject_id')
                        ->when($this->customerId, fn ($q) => $q->where('customer_id', $this->customerId))
                        ->when($this->inquiryId, fn ($q) => $q->where('id', $this->inquiryId));
                }));
            }
            if ($actor->can('operations.manage') && ! $this->inquiryId) {
                $query->orWhere(fn (Builder $query) => $query->where('subject_type', 'Order')->whereExists(function ($parent): void {
                    $parent->selectRaw('1')->from('orders')->whereNull('orders.deleted_at')->whereColumn('orders.id', 'commercial_offer_revisions.subject_id')
                        ->when($this->customerId, fn ($q) => $q->where('customer_id', $this->customerId))
                        ->when($this->orderId, fn ($q) => $q->where('id', $this->orderId));
                })->when($latestOnly && ! $this->orderId, fn ($q) => $q->whereNull('snapshot->origin_inquiry_id')));
            }
        });
        if ($latestOnly) {
            $query->where('revision', function ($latest): void {
                $latest->selectRaw('MAX(latest.revision)')->from('commercial_offer_revisions as latest')
                    ->whereColumn('latest.subject_type', 'commercial_offer_revisions.subject_type')->whereColumn('latest.subject_id', 'commercial_offer_revisions.subject_id');
            });
        }

        return $query;
    }

    public function select(int $id): void
    {
        $this->access();
        $record = $this->query(false)->findOrFail($id);
        if ($record->subject_type === 'OperationInquiry' && Schema::hasTable('customer_portal_submission_items')) {
            $submissionId = CustomerPortalSubmissionItem::where('inquiry_id', $record->subject_id)->value('submission_id');
            if ($submissionId) {
                OperationsAccess::authorize($this->actor(), 'customers.portal.manage');
                $this->redirectRoute('operations.page', ['page' => 'cases', 'view' => 'inbox', 'section' => 'portal', 'customer' => OperationInquiry::findOrFail($record->subject_id)->customer_id, 'source' => 'submission', 'record' => $submissionId], navigate: true);

                return;
            }
        }
        $this->selectedId = $record->id;
        $this->detailGeneration++;
        $this->selectedSubjectType = $record->subject_type;
        $this->selectedSubjectId = $record->subject_id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage('offersPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('offersPage');
    }

    #[On('commercial-offer-updated')]
    public function refreshOffers(?int $inquiryId = null): void
    {
        $this->access();
    }

    public function render()
    {
        $this->access();
        abort_unless(in_array($this->status, ['all', 'draft', 'offered', 'accepted'], true), 422);
        if ($this->selectedId) {
            $this->query(false)->findOrFail($this->selectedId);
        }
        $query = $this->query()->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status));
        if (trim($this->search) !== '') {
            $term = '%'.mb_substr(trim($this->search), 0, 100).'%';
            $query->where(function ($query) use ($term): void {
                foreach (['OperationInquiry' => 'operation_inquiries', 'Order' => 'orders'] as $type => $table) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', $type)->whereExists(fn ($parent) => $parent->selectRaw('1')->from($table)->join('customers', 'customers.id', '=', $table.'.customer_id')->whereColumn($table.'.id', 'commercial_offer_revisions.subject_id')->where(fn ($match) => $match->where($table.'.title', 'like', $term)->orWhere('customers.company_name', 'like', $term))));
                }
            });
        }
        $offers = $query->latest('id')->paginate(15, ['*'], 'offersPage');
        $inquiries = OperationInquiry::with('customer')->whereIn('id', $offers->where('subject_type', 'OperationInquiry')->pluck('subject_id'))->get()->keyBy('id');
        $orders = Order::with('customer')->whereIn('id', $offers->where('subject_type', 'Order')->pluck('subject_id'))->get()->keyBy('id');
        foreach ($offers as $offer) {
            $subject = $offer->subject_type === 'Order' ? $orders->get($offer->subject_id) : $inquiries->get($offer->subject_id);
            $offer->setAttribute('case_title', $subject?->title ?? '—');
            $offer->setAttribute('case_customer', $subject?->customer?->company_name ?? '—');
        }

        return view('livewire.operations.commercial-offer-index', compact('offers'));
    }
}
