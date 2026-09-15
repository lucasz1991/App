<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsAccess;
use Livewire\Component;

class PersonalWorkspace extends Component
{
    public function render()
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());

        return view('livewire.operations.personal-workspace')->layout('layouts.master');
    }
}
