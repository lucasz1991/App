<?php

namespace App\Livewire\Operations;

use App\Models\WorkTimeBasicExport;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\WorkTimeExportAccessService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\ReportingPeriod;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class TimeReview extends Component
{
    use WithPagination;

    #[Locked]
    public bool $exports = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public bool $historyOnly = false;

    public string $filter = 'submitted';

    public string $exportProfile = 'v1';

    public string $search = '';

    public string $from = '';

    public string $until = '';

    #[Locked]
    public array $period = [];

    public function updatedFrom(): void
    {
        $this->updatePeriod();
    }

    public function updatedUntil(): void
    {
        $this->updatePeriod();
    }

    private function updatePeriod(): void
    {
        $this->resetValidation(['from', 'until']);
        $period = [];
        if ($this->from !== '' || $this->until !== '') {
            try {
                ReportingPeriod::bounds($this->from, $this->until);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $this->addError($field, $messages[0]);
                }

                return;
            }
            $period = [$this->from, $this->until];
        }
        if ($period !== $this->period) {
            $this->period = $period;
            $this->updatedFilter();
        }
    }

    public array $notes = [];

    public array $selected = [];

    public bool $batchOpen = false;

    #[Locked]
    public array $batchRows = [];

    public string $batchNote = '';

    #[Locked]
    public bool $detailFromBatch = false;

    public function prepareBatch(): void
    {
        $this->access();
        abort_if($this->exports, 403);
        $this->validate(['selected' => 'required|array|min:1|max:50', 'selected.*' => 'integer|distinct']);
        $entries = $this->entryQuery()->whereIn('id', $this->selected)->where('status', 'submitted')->where('user_id', '!=', auth()->id())->get();
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
        $this->entryQuery()->when($this->exports, fn ($q) => $q->where('status', 'approved'))->findOrFail($id);
        $this->detailFromBatch = false;
        $this->detailId = $id;
        $this->notes = [];
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function openBatchEntry(int $id): void
    {
        abort_unless(! $this->exports && $this->batchOpen && in_array($id, array_column($this->batchRows, 'id'), true), 404);
        $this->openDetails($id);
        $this->detailFromBatch = true;
        $this->batchOpen = false;
    }

    public function updatedDetailOpen(bool $open): void
    {
        if (! $open) {
            $this->closeDetails();
        }
    }

    public function closeDetails(): void
    {
        $this->access();
        $this->reset(['detailOpen', 'detailId', 'notes']);
        $this->resetValidation();
        if (! $this->detailFromBatch) {
            return;
        }

        // Keep only the exact pending revisions previously seen by the reviewer.
        $pending = $this->entryQuery()->whereIn('id', array_column($this->batchRows, 'id'))
            ->where('status', 'submitted')->where('user_id', '!=', auth()->id())->pluck('revision', 'id');
        $this->batchRows = array_values(array_filter($this->batchRows, fn ($row) => $pending->get($row['id']) === $row['revision']));
        $this->selected = array_column($this->batchRows, 'id');
        $this->batchOpen = (bool) $this->batchRows;
        $this->detailFromBatch = false;
        if (! $this->batchOpen) {
            $this->batchNote = '';
        }
    }

    public function mount(bool $exports = false, bool $embedded = false, bool $historyOnly = false, ?int $initialRecordId = null): void
    {
        $this->exports = $exports;
        $this->embedded = $embedded;
        $this->historyOnly = $historyOnly;
        abort_if($historyOnly && ! $exports, 403);
        $this->filter = $exports ? 'approved' : 'submitted';
        $this->access();
        if ($initialRecordId && ! $historyOnly) {
            $this->openDetails($initialRecordId);
        }
    }

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), $this->exports ? 'operations.time.export' : 'operations.time.review');
        OperationsAccess::requireReady();
        Validator::make(['filter' => $this->filter, 'exportProfile' => $this->exportProfile], ['filter' => 'required|in:submitted,returned,approved,all', 'exportProfile' => 'required|in:v1,v2'])->validate();
        if ($this->exports && $this->exportProfile === 'v2') {
            WorkTimeSchema::requireReady();
        }
    }

    private function entryQuery(): Builder
    {
        $query = app(PersonnelScopeService::class)->applyRelatedQuery(WorkTimeEntry::query(), auth()->user(), $this->exports ? 'operations.time.export' : 'operations.time.review');

        return $query->when($this->exports && $this->exportProfile === 'v1', fn ($q) => $q->whereNotNull('shift_assignment_id'));
    }

    public function updatedExportProfile(): void
    {
        $this->access();
        $this->updatedFilter();
        $this->reset(['detailOpen', 'detailId', 'notes']);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function activeFilterCount(): int
    {
        return (int) (trim($this->search) !== '')
            + (int) (! $this->exports && $this->filter !== 'submitted');
    }

    public function resetFilters(): void
    {
        $this->access();
        $this->search = '';
        $this->filter = $this->exports ? 'approved' : 'submitted';
        $this->updatedFilter();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function decide(int $id, int $revision, bool $approve, WorkTimeService $service): void
    {
        $this->access();
        abort_if($this->exports, 403);
        $service->review($id, $revision, $approve, $this->notes[$id] ?? '', auth()->user());
        $this->closeDetails();
        session()->flash('operations.saved', $approve ? 'Zeitmeldung freigegeben.' : 'Zeitmeldung zur Korrektur zurückgegeben.');
    }

    public function export(WorkTimeService $service)
    {
        $this->access();
        abort_unless($this->exports, 403);
        if ($this->period) {
            $query = $this->entryQuery()->whereIn('id', $this->selected);
            ReportingPeriod::apply($query, ...$this->period);
            if ($query->count() !== count($this->selected)) {
                throw ValidationException::withMessages(['workflow' => 'Die Auswahl passt nicht mehr zum angezeigten Zeitraum. Bitte neu auswählen.']);
            }
        }
        if ($this->exportProfile === 'v2') {
            $export = app(WorkTimeExtensionService::class)->export($this->selected, auth()->user());
            $this->selected = [];

            return $this->downloadBasic($export->id);
        }
        $export = $service->export($this->selected, auth()->user());
        $this->selected = [];

        return $this->download($export->id, $service);
    }

    public function download(int $id, WorkTimeService $service)
    {
        $this->access();
        abort_unless($this->exports, 403);
        if ($this->exportProfile === 'v2') {
            return $this->downloadBasic($id);
        }
        $export = app(WorkTimeExportAccessService::class)->legacyQuery(auth()->user())->findOrFail($id);
        $csv = $service->csv($export, auth()->user());

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Zeiten-'.$export->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadPayroll(int $id, PayrollReferenceService $service)
    {
        $this->access();
        abort_unless($this->exports && $this->exportProfile === 'v1', 403);
        $export = app(WorkTimeExportAccessService::class)->legacyQuery(auth()->user())->findOrFail($id);
        $csv = $service->csv($export, auth()->user());

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Lohnuebergabe-'.$export->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadBasic(int $id)
    {
        $this->access();
        abort_unless($this->exports && $this->exportProfile === 'v2', 403);
        $export = WorkTimeBasicExport::findOrFail($id);
        $csv = app(WorkTimeExtensionService::class)->csv($export, auth()->user());

        return response()->streamDownload(fn () => print ($csv), 'RailTime-Arbeitszeiten-v2-'.$export->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $this->access();
        $period = function ($query) {
            if ($this->period) {
                ReportingPeriod::apply($query, ...$this->period);
            }
        };
        $detailEntry = $this->detailId ? $this->entryQuery()->with('user:id,name')->when(WorkTimeSchema::ready(), fn ($q) => $q->with('activities'))->when($this->exports, fn ($q) => $q->where('status', 'approved'))->find($this->detailId) : null;
        if ($detailEntry?->relationLoaded('activities')) {
            $detailEntry->activities->each(fn ($activity) => $activity->setAttribute('display_timezone', $detailEntry->timezone));
        }

        $batchEntries = $this->batchOpen ? $this->entryQuery()->with('user:id,name')->whereIn('id', array_column($this->batchRows, 'id'))->get() : collect();
        $comparisons = $batchEntries->mapWithKeys(fn ($entry) => [$entry->id => app(WorkTimeService::class)->comparison($entry)]);

        $entries = $this->historyOnly ? collect() : $this->entryQuery()->with('user:id,name')->tap($period)->when($this->exports, fn ($q) => $q->where('status', 'approved'))
                ->when($this->exports && $this->exportProfile === 'v1', fn ($q) => $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('work_time_export_items')->whereColumn('work_time_entry_id', 'work_time_entries.id')->whereColumn('work_time_export_items.revision', 'work_time_entries.revision')))
                ->when(! $this->exports && $this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))->when(filled($this->search), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%')))->latest()->paginate(15);
        // Plan/Ist-Vergleich einmal je Zeile; die Tabellenzeile (operations/record) liest time_comparison.
        $entries->each(fn ($entry) => $entry->setAttribute('time_comparison', app(WorkTimeService::class)->comparison($entry)));

        return view('livewire.operations.time-review', [
            'batchEntries' => $batchEntries,
            'comparisons' => $comparisons,
            'warningCount' => $comparisons->filter(fn ($result) => $result['warnings'] !== [])->count(),
            'detailEntry' => $detailEntry,
            'entries' => $entries,
            'history' => ! $this->exports ? collect() : ($this->exportProfile === 'v2' ? app(WorkTimeExportAccessService::class)->basicHistory(auth()->user()) : app(WorkTimeExportAccessService::class)->legacyQuery(auth()->user())->latest('id')->limit(20)->get()),
        ]);
    }
}
