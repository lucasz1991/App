<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\PersonnelTask;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\User;
use App\Services\Operations\PersonnelProcessService;

/**
 * WorkforceAccounts – Fachbereich Personalprozesse: Aufgaben und Schulungen.
 *
 * Zugriff und Profilbindung prüft die Komponente (employee(),
 * authorizeProfileForm()) vor dem Aufruf.
 */
trait SavesPersonnelProcessForms
{
    /** @return bool false, wenn die Formularart nicht zu diesem Fachbereich gehört */
    private function saveProcessForm(User $employee, array $data, User $actor, PersonnelProcessService $process): bool
    {
        if ($this->formKind === 'task') {
            $process->createTask($employee, $data, $actor);
        } elseif ($this->formKind === 'training') {
            $process->createTraining($data, $actor);
        } elseif ($this->formKind === 'enroll') {
            $this->validate(['form.personnel_training_id' => 'required|integer', 'form.revision' => 'required|integer']);
            $process->enroll(PersonnelTraining::findOrFail($data['personnel_training_id']), $employee, (int) $data['revision'], $actor);
        } elseif ($this->formKind === 'participation') {
            $record = PersonnelTrainingParticipant::where('user_id', $employee->id)->findOrFail($this->recordId);
            $process->participation($record, (int) ($data['revision'] ?? 0), $data['action'] ?? '', $data['note'] ?? '', $actor);
        } elseif ($this->formKind === 'task_cancel') {
            $record = PersonnelTask::where('user_id', $employee->id)->findOrFail($this->recordId);
            $process->cancelTask($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
        } elseif ($this->formKind === 'training_cancel') {
            $process->cancelTraining(PersonnelTraining::findOrFail($this->recordId), (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
        } else {
            return false;
        }

        return true;
    }

    public function selectTraining(): void
    {
        $this->access();
        $this->authorizeProfileForm('enroll');
        $training = PersonnelTraining::findOrFail($this->form['personnel_training_id'] ?? 0);
        $this->form['revision'] = $training->revision;
    }

    public function completeTask(int $id, int $revision, PersonnelProcessService $service): void
    {
        $employee = $this->employee();
        $this->authorizeProfileForm('task');
        $service->completeTask(PersonnelTask::where('user_id', $employee->id)->findOrFail($id), $revision, '', auth()->user());
    }
}
