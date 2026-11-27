<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\AbsenceRequest;
use App\Models\EmployeeVacationPolicy;
use App\Models\EmployeeWorkModel;
use App\Models\PersonnelPlanReview;
use App\Models\User;
use App\Models\WorkforceAccountEntry;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkforcePlanReviewService;
use Illuminate\Support\Facades\Validator;

/**
 * WorkforceAccounts – Fachbereich Konten & Arbeitsmodelle: Arbeitsmodell,
 * Urlaubsrichtlinie, Buchungen, Regelzuordnung, Planprüfung, Abwesenheits-
 * korrekturen und Zuständigkeiten.
 *
 * Zugriff und Profilbindung prüft die Komponente (employee(),
 * authorizeProfileForm()) vor dem Aufruf.
 */
trait SavesWorkforceAccountForms
{
    /** @return bool false, wenn die Formularart nicht zu diesem Fachbereich gehört */
    private function saveAccountForm(User $employee, array $data, User $actor, WorkforceAccountService $accounts, PersonnelWorkflowService $workflow): bool
    {
        if ($this->formKind === 'model') {
            $windows = [];
            foreach (range(1, 7) as $day) {
                $start = $data['window_start'][$day] ?? '';
                $end = $data['window_end'][$day] ?? '';
                if ($start !== '' || $end !== '') {
                    $windows[$day] = [['start' => $start, 'end' => $end]];
                }
                $start2 = $data['window_start_2'][$day] ?? '';
                $end2 = $data['window_end_2'][$day] ?? '';
                if ($start2 !== '' || $end2 !== '') {
                    $windows[$day] ??= [];
                    $windows[$day][] = ['start' => $start2, 'end' => $end2];
                }
            }
            $percent = array_filter($data['valuation_percent'] ?? [], fn ($value) => $value !== '' && $value !== null);
            Validator::make(['rates' => $percent], ['rates.*' => 'numeric|between:0,100|decimal:0,2'])->validate();
            $rates = array_map(fn ($rate) => (int) round((float) $rate * 100), $percent);
            unset($data['window_start'], $data['window_end'], $data['window_start_2'], $data['window_end_2'], $data['valuation_percent']);
            $accounts->createModel($employee, $data + ['work_windows' => $windows, 'valuation_rules' => $rates], $actor);
        } elseif ($this->formKind === 'policy') {
            $data['non_working_dates'] = array_values(array_filter(preg_split('/[\s,;]+/', $data['non_working_dates_text'] ?? '')));
            unset($data['non_working_dates_text']);
            $accounts->createPolicy($employee, $data, $actor);
        } elseif ($this->formKind === 'credit') {
            $accounts->creditVacation($employee, $data, $actor);
        } elseif ($this->formKind === 'adjustment') {
            $accounts->adjustTime($employee, $data, $actor);
        } elseif ($this->formKind === 'rule') {
            $accounts->assignRules($employee, $data, $actor);
        } elseif (in_array($this->formKind, ['end_model', 'end_policy'], true)) {
            $class = $this->formKind === 'end_model' ? EmployeeWorkModel::class : EmployeeVacationPolicy::class;
            $record = $class::where('user_id', $employee->id)->findOrFail($this->recordId);
            $accounts->endVersion($record, (int) ($data['revision'] ?? 0), $data['ends_on'] ?? '', $data['note'] ?? '', $actor);
        } elseif ($this->formKind === 'reduce_credit') {
            $record = WorkforceAccountEntry::where('user_id', $employee->id)->findOrFail($this->recordId);
            $accounts->reduceCreditAmount($record, $data['quantity'] ?? null, $data['note'] ?? '', $actor, $data['idempotency_key'] ?? null);
        } elseif ($this->formKind === 'sickness_correction') {
            $record = AbsenceRequest::where('user_id', $employee->id)->findOrFail($this->recordId);
            $workflow->correctSickness($record, (int) ($data['revision'] ?? 0), $data, $actor);
        } elseif ($this->formKind === 'responsibility') {
            app(PersonnelScopeService::class)->assign($employee, $data, $actor);
        } elseif ($this->formKind === 'adopt_absence') {
            $record = AbsenceRequest::where('user_id', $employee->id)->findOrFail($this->recordId);
            $accounts->adoptApprovedAbsence($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
        } elseif ($this->formKind === 'plan_review') {
            $record = PersonnelPlanReview::where('user_id', $employee->id)->findOrFail($this->recordId);
            app(WorkforcePlanReviewService::class)->reassess($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
        } else {
            return false;
        }

        return true;
    }

    public function activate(string $kind, int $id, int $revision, WorkforceAccountService $service): void
    {
        $employee = $this->employee();
        $this->authorizeProfileForm($kind);
        abort_if($this->personal, 403);
        abort_unless(in_array($kind, ['model', 'policy'], true), 404);
        $class = $kind === 'model' ? EmployeeWorkModel::class : EmployeeVacationPolicy::class;
        $service->activate($class::where('user_id', $employee->id)->findOrFail($id), $revision, auth()->user());
    }
}
