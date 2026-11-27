<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\User;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\WorkforceAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * WorkforceAccounts – persönliche Ansicht: Urlaubsantrag und Krankmeldung
 * des angemeldeten Mitarbeiters.
 */
trait SavesPersonalAbsenceForms
{
    /** @return bool false, wenn die Formularart keine persönliche Abwesenheit ist */
    private function savePersonalAbsenceForm(User $employee, array $data, User $actor, WorkforceAccountService $accounts, PersonnelWorkflowService $workflow): bool
    {
        if ($this->formKind === 'vacation') {
            $this->validate(['form.starts_on' => 'required|date_format:Y-m-d', 'form.ends_on' => 'required|date_format:Y-m-d|after_or_equal:form.starts_on']);
            $timezone = $accounts->effectiveModel($employee, $data['starts_on'])?->timezone ?? config('operations.display_timezone');
            $partial = $data['vacation_fraction'] !== '1' && $data['vacation_fraction'] !== 1;
            if ($partial) {
                $this->validate(['form.starts_time' => 'required|date_format:H:i', 'form.ends_time' => 'required|date_format:H:i', 'form.ends_on' => 'same:form.starts_on']);
            }
            $workflow->requestAbsence($actor, ['kind' => 'vacation', 'starts_at' => $data['starts_on'].'T'.($partial ? $data['starts_time'] : '00:00'), 'ends_at' => $partial ? $data['ends_on'].'T'.$data['ends_time'] : CarbonImmutable::parse($data['ends_on'])->addDay()->format('Y-m-d\T00:00'), 'timezone' => $timezone, 'vacation_fraction' => $data['vacation_fraction'] === 'window' ? '1' : $data['vacation_fraction'], 'note' => $data['note'] ?? '']);
        } elseif ($this->formKind === 'sick') {
            try {
                $workflow->reportSickness($actor, $data);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [array_key_exists($key, $data) ? 'form.'.$key : $key => $messages])->all());
            }
        } else {
            return false;
        }

        return true;
    }
}
