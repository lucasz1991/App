<?php

namespace App\Livewire\Operations;

use App\Models\WorkTimeCaptureReceipt;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\PersonnelScopeService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class WorkTimeCaptureReview extends Component
{
    use WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public ?int $selectedId = null;

    public bool $detailOpen = false;

    public string $note = '';

    public bool $confirmReview = false;

    public function mount(bool $personal = false, ?int $initialRecordId = null): void
    {
        $this->personal = $personal;
        $this->query();
        if ($initialRecordId) {
            $this->open($initialRecordId);
        }
    }

    private function query()
    {
        WorkTimeSchema::requireReady();
        if ($this->personal) {
            OperationsAccess::own(auth()->user(), auth()->id());
            $ids = [auth()->id()];
        } else {
            $ids = app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'operations.time.review');
        }

        return WorkTimeCaptureReceipt::where('status', 'conflict')->when($ids !== null, fn ($q) => $q->whereHas('device', fn ($q) => $q->whereIn('user_id', $ids)));
    }

    public function open(int $id): void
    {
        $this->query()->findOrFail($id);
        $this->selectedId = $id;
        $this->note = '';
        $this->confirmReview = false;
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function openPersonalEvent(?string $eventKey): bool
    {
        abort_unless($this->personal, 403);
        if (! $eventKey || ! Str::isUuid($eventKey)) {
            return false;
        }
        $id = $this->query()->where('event_key', $eventKey)->value('id');
        if (! $id) {
            return false;
        }
        $this->open($id);

        return true;
    }

    public function prepareReview(): void
    {
        abort_if($this->personal, 403);
        $receipt = $this->query()->findOrFail($this->selectedId);
        abort_if((int) $receipt->device->user_id === auth()->id(), 403);
        $this->validate(['note' => 'required|string|min:5|max:1000']);
        $this->confirmReview = true;
    }

    public function reviewed(): void
    {
        abort_if($this->personal, 403);
        $this->validate(['note' => 'required|string|min:5|max:1000']);
        OperationsTransaction::run(function () {
            $receipt = $this->query()->lockForUpdate()->findOrFail($this->selectedId);
            $subjectId = (int) $receipt->device->user_id;
            abort_if($subjectId === auth()->id(), 403);
            app(PersonnelScopeService::class)->authorize(auth()->user(), $subjectId, 'operations.time.review');
            $receipt->update(['status' => 'reviewed', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()->utc(), 'review_note' => $this->note]);
            app(OperationsAuditService::class)->record($receipt, auth()->user(), 'time.capture_reviewed', ['note' => $this->note, 'applied' => false]);
        }, 3);
        $this->reset(['detailOpen', 'confirmReview', 'selectedId', 'note']);
        session()->flash('operations.saved', 'Prüfung dokumentiert. Keine Arbeitszeit automatisch geändert.');
    }

    public function render()
    {
        $query = $this->query();
        $selected = $this->detailOpen && $this->selectedId ? (clone $query)->with('device')->find($this->selectedId) : null;

        return view('livewire.operations.work-time-capture-review', ['records' => $query->latest('id')->paginate(10, ['*'], 'captureReviewPage'), 'selected' => $selected, 'payload' => $selected?->payload]);
    }
}
