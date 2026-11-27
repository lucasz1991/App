<?php

namespace App\Livewire\Operations;

use App\Models\WorkTimeEntry;
use App\Services\Operations\WorkTimeDayReviewService;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkTimeDayReview extends Component
{
    #[Locked] public int $entryId;
    #[Locked] public int $revision;
    #[Locked] public string $basis;
    #[Locked] public string $eventKey;
    #[Locked] public array $summary = [];
    #[Locked] public array $accepted = [];
    #[Locked] public array $breaks = [];
    #[Locked] public bool $needsBreakDetails = false;
    #[Locked] public bool $confirmed = false;
    public array $fields = [];
    public bool $editing = false;

    private function entry(): WorkTimeEntry
    {
        OperationsAccess::own(auth()->user(), auth()->id());

        return WorkTimeEntry::where('user_id', auth()->id())->findOrFail($this->entryId);
    }

    public function mount(int $entryId, WorkTimeDayReviewService $service): void
    {
        $this->entryId = $entryId;
        $entry = $this->entry();
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 409);
        $draft = $service->draft($entry);
        $this->basis = $draft['basis'];
        $this->revision = $entry->revision;
        $this->eventKey = (string) Str::uuid();
        $this->fields = $this->accepted = $draft['fields'];
        $this->summary = $service->calculate($entry, $draft, $this->accepted);
        $this->needsBreakDetails = $draft['issue'] !== null;
        foreach ($draft['breaks'] as [$from, $to]) {
            $format = $entry->starts_at->isSameDay($entry->ends_at) ? 'H:i' : 'd.m. H:i';
            $this->breaks[] = CarbonImmutable::createFromTimestampUTC($from)->setTimezone($entry->timezone)->format($format)
                .'–'.CarbonImmutable::createFromTimestampUTC($to)->setTimezone($entry->timezone)->format($format);
        }
    }

    public function edit(): void
    {
        $this->entry();
        $this->fields = $this->accepted;
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->editing = false;
        $this->dispatch('day-review-focus', target: 'editButton')->self();
    }

    public function exception(\Throwable $e): void
    {
        if ($e instanceof ValidationException) {
            $this->dispatch('day-review-focus', target: 'reviewError')->self();
        }
    }

    public function apply(WorkTimeDayReviewService $service): void
    {
        $entry = $this->entry();
        $draft = $service->draft($entry);
        if (! hash_equals($this->basis, $draft['basis'])) {
            throw ValidationException::withMessages(['dayReview' => 'Die Zeitmeldung oder der Dienstplan wurde geändert. Bitte den Tagesbeleg neu öffnen.']);
        }
        $summary = $service->calculate($entry, $draft, $this->fields);
        if ($summary['issue']) {
            throw ValidationException::withMessages(['dayReview' => $summary['issue']]);
        }
        $this->summary = $summary;
        $this->accepted = $this->fields;
        $this->cancel();
    }

    public function confirm(WorkTimeDayReviewService $service): void
    {
        $service->confirm($this->entryId, $this->revision, $this->basis, $this->accepted, $this->eventKey, auth()->user());
        $this->confirmed = true;
        $this->dispatch('work-time-day-confirmed', entryId: $this->entryId);
    }

    public function render()
    {
        return view('livewire.operations.work-time-day-review', ['entry' => $this->entry()]);
    }
}
