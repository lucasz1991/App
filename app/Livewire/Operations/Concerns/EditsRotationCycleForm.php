<?php

namespace App\Livewire\Operations\Concerns;

/**
 * PlanningEnhancements – Rotationsformular: Zyklusdienste und Ausnahmen
 * hinzufügen und entfernen.
 */
trait EditsRotationCycleForm
{
    public function addSlot(): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && count($this->form['slots']) < 50, 422);
        $this->form['slots'][] = ['day' => 0, 'shift_id' => '', 'team_id' => null, 'offset' => 0];
    }

    public function removeSlot(int $index): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && isset($this->form['slots'][$index]) && count($this->form['slots']) > 1, 422);
        array_splice($this->form['slots'], $index, 1);
        $this->preview = [];
    }

    public function addException(): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && count($this->form['exceptions']) < 366, 422);
        $this->form['exceptions'][] = ['date' => $this->from, 'user_id' => null];
    }

    public function removeException(int $index): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && isset($this->form['exceptions'][$index]), 422);
        array_splice($this->form['exceptions'], $index, 1);
    }
}
