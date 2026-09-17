<?php

namespace App\Livewire\Operations;

use App\Models\WorkTimeEntry;
use App\Models\WorkTimeExport;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\ReportingPeriod;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class TimeReview extends Component
{
    use WithPagination;

    #[Locked]
    public bool $exports = false;

    public string $filter = 'submitted';

    public string $search = '';

    public string $from = '';

    public string $until = '';

    public function updatedFrom(): void
    {
        $this->updatedFilter();
    }

    public function updatedUntil(): void
    {
        $this->updatedFilter();
    }

    public array $notes = [];

    public array $selected = [];

    public bool $batchOpen = false;

    #[Locked]
    public array $batchRows = [];

    public string $batchNote = '';

    public function prepareBatch(): void
    {
        $this->access();
        abort_if($this->exports, 403);
        $this->validate(['selected' => 'required|array|min:1|max:50', 'selected.*' => 'integer|distinct']);
        $entries = WorkTimeEntry::whereIn('id', $this->selected)->where('status', 'submitted')->where('user_id', '!=', auth()->id())->get();
        if ($entries->count() !== count($this->selected)) {
            throw ValidationException::withMessages(['workflow' => 'Nur fremde eingereichte Zeitmeldungen auswählen.']);
        }
        $this->batchRows = $entries->map(fn ($entry) => ['id' => $entry->id, 'revision' => $entry->revision])->all();
        $this->batchNote = '';
        $this->batchOpen = true;
    }

    public function reviewBatch(bool $approve, WorkTimeService $service): void
    {
        $this->access();
        abort_if($this->exports, 403);
        $service->reviewBatch($this->batchRows, $approve, $this->batchNote, auth()->user());
        $this->reset(['selected', 'batchRows', 'batchNote', 'batchOpen']);
        session()->flash('operations.saved', 'Auswahl bearbeitet.');
    }

    public bool $detailOpen = false;

    #[Locked]
    public ?int $detailId = null;

    public function openDetails(int $id): void
    {
        $this->access();
        WorkTimeEntry::when($this->exports, fn ($q) => $q->where('status', 'approved'))->findOrFail($id);
        $this->detailId = $id;
        $this->notes = [];
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function mount(bool $exports = false): void
    {
        $this->exports = $exports;
        $this->filter = $exports ? 'approved' : 'submitted';
        $this->access();
    }

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), $this->exports ? 'operations.time.export' : 'operations.time.review');
        OperationsAccess::requireReady();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function decide(int $id, int $revision, bool $approve, WorkTimeService $service): void
    {
        $this->access();
        $service->review($id, $revision, $approve, $this->notes[$id] ?? '', auth()->user());
        unset($this->notes[$id]);
        $this->detailOpen = false;
        $this->resetValidation();
    }

    public function export(WorkTimeService $service)
    {
        $this->access();
        $export = $service->export($this->selected, auth()->user());
        $this->selected = [];

        return $this->download($export->id, $service);
    }

    public function download(int $id, WorkTimeService $service)
    {
        $this->access();
        $export = WorkTimeExport::findOrFail($id);
        $csv = $service->csv($export, auth()->user());

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Zeiten-'.$export->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadPayroll(int $id, PayrollReferenceService $service)
    {
        $this->access();
        $export = WorkTimeExport::findOrFail($id);
        $csv = $service->csv($export, auth()->user());

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Lohnuebergabe-'.$export->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $this->access();
        $period = function ($query) {
            if ($this->from && $this->until) {
                ReportingPeriod::apply($query, $this->from, $this->until);
            }
        };

        return view('livewire.operations.time-review', [
            'batchEntries' => $this->batchOpen ? WorkTimeEntry::with('user:id,name')->whereIn('id', array_column($this->batchRows, 'id'))->get() : collect(),
            'detailEntry' => $this->detailId ? WorkTimeEntry::with('user:id,name')->when($this->exports, fn ($q) => $q->where('status', 'approved'))->find($this->detailId) : null,
            'entries' => WorkTimeEntry::with('user:id,name')->tap($period)->when($this->exports, fn ($q) => $q->where('status', 'approved')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('work_time_export_items')->whereColumn('work_time_entry_id', 'work_time_entries.id')->whereColumn('work_time_export_items.revision', 'work_time_entries.revision')))
                ->when(! $this->exports && $this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))->when(filled($this->search), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%')))->latest()->paginate(15),
            'history' => $this->exports ? WorkTimeExport::latest('id')->limit(20)->get() : collect(),
        ]);
    }
}
