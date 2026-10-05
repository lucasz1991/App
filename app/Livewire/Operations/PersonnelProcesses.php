<?php

namespace App\Livewire\Operations;

use Livewire\Attributes\Locked;

class PersonnelProcesses extends WorkforceAccounts
{
    #[Locked]
    public bool $processOnly = true;

    public string $tab = 'tasks';
}
