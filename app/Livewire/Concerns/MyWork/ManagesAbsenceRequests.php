<?php

namespace App\Livewire\Concerns\MyWork;

use App\Models\AbsenceRequest;
use App\Services\Operations\PersonnelWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Fokusansicht „Urlaub & Abwesenheit": Antrag stellen, zurückziehen, eigene Anträge listen
 * (Paginator absencesPage).
 *
 * Erwartet in der Komponente: access(), withFormErrors() und $tab.
 */
trait ManagesAbsenceRequests
{
    abstract private function access(): void;
    abstract private function withFormErrors(string $prefix, callable $action): mixed;

    public bool $absenceOpen = false;

    public bool $absenceWholeDays = true;

    public string $absenceFirstDay = '';

    public string $absenceLastDay = '';

    public array $absence = ['kind' => 'vacation', 'starts_at' => '', 'ends_at' => '', 'timezone' => 'Europe/Berlin', 'note' => ''];

    public function requestAbsence(PersonnelWorkflowService $service): void
    {
        $this->access();
        $this->resetValidation();
        $data = $this->absence;
        if ($this->absenceWholeDays) {
            $this->validate([
                'absenceFirstDay' => 'required|date_format:Y-m-d',
                'absenceLastDay' => 'required|date_format:Y-m-d|after_or_equal:absenceFirstDay',
                'absence.timezone' => 'required|timezone',
            ]);
            $firstDay = CarbonImmutable::parse($this->absenceFirstDay, $data['timezone'])->startOfDay();
            $lastDay = CarbonImmutable::parse($this->absenceLastDay, $data['timezone'])->startOfDay();
            foreach (['absenceFirstDay' => $firstDay, 'absenceLastDay' => $lastDay] as $field => $day) {
                if ($day->format('Y-m-d') !== $this->{$field}) {
                    throw ValidationException::withMessages([$field => 'Dieser Tag existiert in der gewählten Zeitzone nicht.']);
                }
            }
            $data['starts_at'] = $firstDay->format('Y-m-d\TH:i');
            $data['ends_at'] = $lastDay->addDay()->startOfDay()->format('Y-m-d\TH:i');
        }
        $this->withFormErrors('absence', fn () => $service->requestAbsence(auth()->user(), $data));
        $this->reset(['absence', 'absenceWholeDays', 'absenceFirstDay', 'absenceLastDay']);
        $this->absenceOpen = false;
        session()->flash('operations.saved', 'Antrag eingereicht.');
    }

    public function withdraw(int $id, int $revision, PersonnelWorkflowService $service): void
    {
        $this->access();
        $service->absence(AbsenceRequest::where('user_id', auth()->id())->findOrFail($id), $revision, 'withdraw', '', auth()->user());
    }

    /** Daten für die Fokusansicht „Urlaub & Abwesenheit". */
    private function absencesViewData(): array
    {
        return [
            'absences' => $this->tab === 'absences' ? AbsenceRequest::where('user_id', auth()->id())->latest()->paginate(20, ['*'], 'absencesPage') : collect(),
        ];
    }
}
