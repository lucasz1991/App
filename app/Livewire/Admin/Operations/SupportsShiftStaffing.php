<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\ShiftAssignmentExceptionService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffRegionalPreferenceService;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Local drawer state; all assignment decisions are rechecked by the services. */
trait SupportsShiftStaffing
{
    public string $candidateQualification = '';

    public string $candidatePool = '';

    public string $candidateSuitability = 'all';

    public string $candidateRegion = 'all';

    #[Locked]
    public bool $candidatesReady = false;

    #[Locked]
    public int $candidateLimit = 12;

    #[Locked]
    public array $assignmentReview = [];

    public string $exceptionReason = '';

    public bool $exceptionAcknowledged = false;

    #[Locked]
    public bool $exceptionPrepared = false;

    public string $exceptionConfirmation = '';

    #[Locked]
    public ?int $regionalEmployeeId = null;

    #[Locked]
    public int $regionalRevision = 0;

    public array $regionalPreference = [];

    public function loadStaffingCandidates(): void
    {
        $this->ensureAdmin();
        abort_unless($this->detailOpen && $this->selectedShiftId, 404);
        $this->candidatesReady = true;
    }

    public function loadMoreCandidates(): void
    {
        $this->loadStaffingCandidates();
        $this->candidateLimit = min(5000, $this->candidateLimit + 12);
    }

    public function updatedCandidateQualification(): void
    {
        $this->resetCandidateWindow();
    }

    public function updatedCandidatePool(): void
    {
        $this->resetCandidateWindow();
    }

    public function updatedCandidateSuitability(): void
    {
        $this->resetCandidateWindow();
    }

    public function updatedCandidateRegion(): void
    {
        $this->resetCandidateWindow();
    }

    public function resetCandidateFilters(): void
    {
        $this->ensureAdmin();
        $this->reset(['candidateSearch', 'candidateQualification', 'candidatePool', 'candidateSuitability', 'candidateRegion']);
        $this->resetCandidateWindow();
    }

    private function resetCandidateWindow(): void
    {
        $this->candidateLimit = 12;
        $this->resetPage('candidatesPage');
    }

    public function cancelCandidateSelection(): void
    {
        $this->ensureAdmin();
        $this->reset(['employeeId', 'assignmentReview', 'exceptionReason', 'exceptionAcknowledged', 'exceptionPrepared', 'exceptionConfirmation']);
        $this->resetValidation(['assignment', 'workflow', 'exceptionReason', 'exceptionAcknowledged', 'exceptionConfirmation']);
    }

    public function prepareAssignmentException(): void
    {
        $this->ensureAdmin();
        abort_unless($this->detailOpen && $this->selectedShiftId && $this->employeeId, 404);
        $this->validate([
            'exceptionReason' => ['required', 'string', 'min:20', 'max:2000'],
            'exceptionAcknowledged' => ['accepted'],
        ], [
            'exceptionReason.min' => 'Bitte die konkrete Lösung, Pausen und Verantwortung nachvollziehbar beschreiben (mindestens 20 Zeichen).',
            'exceptionAcknowledged.accepted' => 'Bitte die Prüfung der Konflikte und Pflichten ausdrücklich bestätigen.',
        ]);
        $review = app(ShiftAssignmentExceptionService::class)->review(Shift::findOrFail($this->selectedShiftId), User::findOrFail($this->employeeId), auth()->user());
        if (! ($review['can_override'] ?? false) || ($review['fingerprint'] ?? '') !== ($this->assignmentReview['fingerprint'] ?? null)) {
            $this->assignmentReview = $review;
            $this->exceptionPrepared = false;
            $this->exceptionAcknowledged = false;
            $this->addError('assignment', 'Die Prüfung hat sich geändert. Bitte die aktuellen Hinweise erneut prüfen und bestätigen.');

            return;
        }
        $this->exceptionPrepared = true;
        $this->exceptionConfirmation = '';
    }

    public function confirmAssignmentException(ShiftAssignmentService $service): void
    {
        $this->ensureAdmin();
        abort_unless($this->detailOpen && $this->selectedShiftId && $this->employeeId && $this->exceptionPrepared, 403);
        $this->validate([
            'exceptionConfirmation' => ['required', 'in:FREIGEBEN'],
            'exceptionReason' => ['required', 'string', 'min:20', 'max:2000'],
            'exceptionAcknowledged' => ['accepted'],
            'assignmentStatus' => ['required', Rule::in(ShiftAssignmentStatus::blockingValues())],
            'assignmentNote' => ['nullable', 'string', 'max:2000'],
        ], ['exceptionConfirmation.in' => 'Bitte FREIGEBEN eingeben.', 'exceptionConfirmation.required' => 'Bitte FREIGEBEN eingeben.']);
        try {
            $service->assign(Shift::findOrFail($this->selectedShiftId), User::findOrFail($this->employeeId), auth()->user(), $this->assignmentStatus,
                trim($this->assignmentNote) ?: null, $this->selectedPlanRevision, [
                    'fingerprint' => $this->assignmentReview['fingerprint'] ?? '',
                    'reason' => trim($this->exceptionReason), 'acknowledged' => true,
                ]);
            $this->cancelCandidateSelection();
            $this->assignmentNote = '';
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: 'Zuweisung mit dokumentierter Ausnahme gespeichert.');
        } catch (ValidationException $exception) {
            $this->exceptionPrepared = false;
            $this->exceptionAcknowledged = false;
            $this->addError('assignment', collect($exception->errors())->flatten()->first());
        } catch (\DomainException $exception) {
            $this->exceptionPrepared = false;
            $this->addError('assignment', $exception->getMessage());
        }
    }

    public function editRegionalPreference(int $userId): void
    {
        $this->ensureAdmin();
        abort_unless($this->detailOpen, 404);
        abort_unless(app(ShiftAssignmentExceptionService::class)->canOverride(auth()->user()), 403);
        if (! app(StaffRegionalPreferenceService::class)->ready()) {
            $this->addError('assignment', 'Regionale Einsatzgebiete sind noch nicht eingerichtet. Die zugehörige Datenbankerweiterung muss zuerst aktiviert werden.');

            return;
        }
        $employee = User::where('role', 'staff')->where('status', true)->findOrFail($userId);
        $preferences = app(StaffRegionalPreferenceService::class)->get($employee);
        $this->regionalEmployeeId = $employee->id;
        $this->regionalRevision = $preferences['revision'];
        $this->regionalPreference = $preferences;
        $this->resetValidation();
    }

    public function addNoGoArea(): void
    {
        $this->ensureAdmin();
        abort_unless($this->regionalEmployeeId, 404);
        if (count($this->regionalPreference['no_go_areas'] ?? []) < 10) {
            $this->regionalPreference['no_go_areas'][] = ['location' => '', 'radius_km' => 25];
        }
    }

    public function removeNoGoArea(int $index): void
    {
        $this->ensureAdmin();
        unset($this->regionalPreference['no_go_areas'][$index]);
        $this->regionalPreference['no_go_areas'] = array_values($this->regionalPreference['no_go_areas'] ?? []);
    }

    public function closeRegionalPreference(): void
    {
        $this->ensureAdmin();
        $this->reset(['regionalEmployeeId', 'regionalRevision', 'regionalPreference']);
    }

    public function saveRegionalPreference(): void
    {
        $this->ensureAdmin();
        abort_unless($this->regionalEmployeeId && $this->detailOpen, 404);
        try {
            $config = Arr::except($this->regionalPreference, ['revision']);
            app(StaffRegionalPreferenceService::class)->save(User::findOrFail($this->regionalEmployeeId), auth()->user(), $config, $this->regionalRevision);
            $this->closeRegionalPreference();
            $this->cancelCandidateSelection();
            $this->resetCandidateWindow();
            $this->dispatch('swal:toast', type: 'success', text: 'Einsatzgebiete gespeichert.');
        } catch (ValidationException $exception) {
            $this->addError('regionalPreference', collect($exception->errors())->flatten()->first());
        }
    }
}
