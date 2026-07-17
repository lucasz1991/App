<?php

namespace App\Livewire;

use Livewire\Component;

class UserFiles extends Component
{
    public function render()
    {
        $teams = auth()->user()
            ->teams()
            ->where('personal_team', false)
            ->orderBy('name')
            ->get(['teams.id', 'teams.name']);

        return view('livewire.user-files', compact('teams'))
            ->layout('layouts.master', ['area' => 'user']);
    }
}
