<?php

namespace App\Livewire\Employees\Profile;

use App\Models\DropboxIdentity;
use App\Models\DutyActivity;
use App\Models\EmployeeCompetencyFact;
use App\Models\EmployeeQualification;
use App\Models\User;
use App\Services\Dropbox\ReportedQualificationEvidence;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\PersonnelScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeDutyActivities extends Component
{
    #[Locked]
    public int $userId;

    public ?int $activityId = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
    }

    public function assign(): void
    {
        $this->access('employees.master-data.edit');
        $activity = DutyActivity::where('active', true)->findOrFail($this->activityId);
        $this->updateAssignment($activity->id, true);
        $this->activityId = null;
    }

    public function remove(int $id): void
    {
        $this->access('employees.master-data.edit');
        $this->updateAssignment($id, false);
    }

    private function access(string $ability): void
    {
        app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, $ability);
    }

    private function updateAssignment(int $id, bool $assign): void
    {
        DB::transaction(function () use ($id, $assign) {
            $employee = User::lockForUpdate()->findOrFail($this->userId);
            $exists = $employee->dutyActivities()->whereKey($id)->exists();
            if ($exists === $assign) {
                return;
            }
            if ($assign) {
                $employee->dutyActivities()->attach($id, ['confirmed_by' => auth()->id(), 'confirmed_at' => now()]);
            } else {
                $employee->dutyActivities()->detach($id);
            }
            app(OperationsAuditService::class)->record($employee, auth()->user(), $assign ? 'activity.assigned' : 'activity.removed', ['activity_id' => $id]);
        });
    }

    private function reportedQualifications(User $employee): Collection
    {
        if (! Schema::hasTable('dropbox_identities') || ! Schema::hasTable('employee_competency_facts')) {
            return collect();
        }

        return EmployeeCompetencyFact::where('kind', ReportedQualificationEvidence::KIND)
            ->whereIn('identity_id', DropboxIdentity::where('user_id', $employee->id)->where('kind', 'employee')->select('id'))
            ->where('value->status', 'reported')->orderBy('id')->get(['value'])
            ->map(fn (EmployeeCompetencyFact $fact) => $fact->value['value'] ?? null)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')->uniqueStrict()->values();
    }

    public function render()
    {
        $this->access('employees.master-data.view');
        $employee = User::findOrFail($this->userId);
        $assigned = $employee->dutyActivities()->with('bundle')->orderBy('code')->get();
        $available = DutyActivity::with('bundle')->where('active', true)->whereNotIn('id', $assigned->modelKeys())->orderBy('code')->get();
        $codes = preg_split('~\s*[/,;|]\s*~u', mb_strtolower(trim($employee->profile?->position ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $suggestions = $available->filter(fn ($a) => in_array(mb_strtolower($a->code), $codes, true));
        $canEdit = app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit');

        $qualificationState = [];
        $qualified = collect();
        $reportedQualifications = collect();
        if (app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'operations.qualifications.manage')) {
            $reportedQualifications = $this->reportedQualifications($employee);
            $today = now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
            $valid = EmployeeQualification::where('user_id', $this->userId)->where('status', 'approved')
                ->whereHas('type', fn ($q) => $q->where('is_active', true))
                ->whereDate('valid_from', '<=', $today)->whereDate('valid_until', '>=', $today)->pluck('qualification_type_id');
            foreach ($available->concat($assigned) as $item) {
                $mandatory = collect($item->bundle?->requirements)->where('mandatory', true)->pluck('id');
                if (! $item->active || ! $item->requirements_confirmed) {
                    $qualificationState[$item->id] = 'Anforderungen offen';
                } elseif ($mandatory->isEmpty()) {
                    $qualificationState[$item->id] = 'Keine Pflichtnachweise im Katalog';
                } elseif ($mandatory->diff($valid)->isNotEmpty()) {
                    $qualificationState[$item->id] = 'Pflichtnachweis fehlt oder ist abgelaufen';
                } else {
                    $qualificationState[$item->id] = 'Pflichtnachweise heute vollständig';
                    if ($available->contains('id', $item->id)) {
                        $qualified->push($item);
                    }
                }
            }
        }

        return view('livewire.employees.profile.employee-duty-activities', compact('employee', 'assigned', 'available', 'suggestions', 'canEdit', 'qualificationState', 'qualified', 'reportedQualifications'));
    }
}
