<?php

namespace App\Livewire\Operations;

use App\Models\DutyReport;
use App\Models\Shift;
use App\Models\ShiftSection;
use App\Services\Operations\DutyActivityService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\PlanningSchema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DutyActivity extends Component
{
    #[Locked]
    public int $shiftId;

    #[Locked]
    public bool $employeeMode = false;

    #[Locked]
    public int $planRevision;

    #[Locked]
    public ?int $sectionId = null;

    #[Locked]
    public ?int $reportId = null;

    #[Locked]
    public ?int $reportRevision = null;

    #[Locked]
    public string $requestKey = '';

    public bool $sectionOpen = false;

    public bool $reportOpen = false;

    public bool $resolveOpen = false;

    public array $section = ['kind' => 'preparation', 'label' => '', 'starts_at' => '', 'ends_at' => ''];

    public array $report = ['kind' => 'information', 'delay_minutes' => null, 'message' => ''];

    public string $resolution = '';

    private function access(): Shift
    {
        PlanningSchema::requireReady();
        $shift = Shift::findOrFail($this->shiftId);
        if ($this->employeeMode) {
            $assignment = $shift->assignments()->where('user_id', auth()->id())->first();
            abort_unless($assignment, 404);
            app(PersonalSchedule::class)->assignment(auth()->user(), $assignment->id);
        } else {
            OperationsAccess::authorize(auth()->user(), 'operations.manage');
        }

        return $shift;
    }

    public function editSection(?int $id = null): void
    {
        $shift = $this->access();
        abort_if($this->employeeMode, 403);
        $this->reset('section');
        $this->sectionId = $id;
        $this->planRevision = $shift->revision;
        if ($id) {
            $section = ShiftSection::where('shift_id', $shift->id)->findOrFail($id);
            $this->section = $section->only(['kind', 'label']) + ['starts_at' => $section->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $section->ends_at->format('Y-m-d\TH:i')];
        }
        $this->sectionOpen = true;
        $this->resetValidation();
    }

    public function saveSection(DutyActivityService $service): void
    {
        $this->access();
        abort_if($this->employeeMode, 403);
        $service->section($this->shiftId, $this->planRevision, $this->sectionId, $this->section, auth()->user());
        $this->sectionOpen = false;
        $this->dispatch('operations-plan-changed');
    }

    public function removeSection(int $id, int $revision, DutyActivityService $service): void
    {
        $this->access();
        abort_if($this->employeeMode, 403);
        $service->section($this->shiftId, $revision, $id, null, auth()->user());
        $this->dispatch('operations-plan-changed');
    }

    public function newReport(): void
    {
        $shift = $this->access();
        $this->planRevision = $this->employeeMode ? $shift->published_revision : $shift->revision;
        $this->reset('report');
        $this->requestKey = (string) Str::uuid();
        $this->reportOpen = true;
        $this->resetValidation();
    }

    public function sendReport(DutyActivityService $service): void
    {
        $this->access();
        $service->report($this->shiftId, $this->planRevision, $this->report, $this->requestKey, auth()->user());
        $this->reportOpen = false;
    }

    public function openResolution(int $id): void
    {
        $this->access();
        abort_if($this->employeeMode, 403);
        $record = DutyReport::where('shift_id', $this->shiftId)->findOrFail($id);
        $this->reportId = $id;
        $this->reportRevision = $record->revision;
        $this->resolution = '';
        $this->resolveOpen = true;
        $this->resetValidation();
    }

    public function resolve(DutyActivityService $service): void
    {
        $this->access();
        abort_if($this->employeeMode, 403);
        DutyReport::where('shift_id', $this->shiftId)->findOrFail($this->reportId);
        $service->resolve($this->reportId, $this->reportRevision, $this->resolution, auth()->user());
        $this->resolveOpen = false;
    }

    public function render()
    {
        $shift = $this->access();
        // Employee sections come exclusively from the last published snapshot.
        $sections = $this->employeeMode ? collect($shift->published_snapshot['sections'] ?? [])->map(fn ($row) => (object) $row)
            : collect(app(DutyActivityService::class)->snapshot($shift))->map(fn ($row) => (object) ($row + ['plan_revision' => $shift->revision]));

        return view('livewire.operations.duty-activity', [
            'sections' => $sections, 'currentRevision' => $shift->revision,
            'reports' => DutyReport::where('shift_id', $shift->id)->when($this->employeeMode, fn ($q) => $q->where('user_id', auth()->id()))->with('user:id,name')->latest()->get(),
            'sectionKinds' => DutyActivityService::KINDS, 'reportKinds' => DutyActivityService::REPORTS,
        ]);
    }
}
