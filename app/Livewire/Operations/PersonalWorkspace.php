<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalWorkspaceAreas;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonalWorkspace extends Component
{
    #[Locked]
    public string $area = 'work';

    #[Locked]
    public string $initialTab = '';

    public function mount(): void
    {
        [$this->area, $this->initialTab] = PersonalWorkspaceAreas::resolve(request()->query('area'), request()->query('tab'));
    }

    public function render()
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
        $definition = PersonalWorkspaceAreas::get($this->area);
        abort_unless($definition, 503);

        return view('livewire.operations.personal-workspace', ['definition' => $definition])->layout('layouts.master');
    }
}
