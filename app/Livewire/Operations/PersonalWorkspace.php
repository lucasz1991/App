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

    #[Locked]
    public array $initialRequest = [];

    public function mount(): void
    {
        $area = request()->query('area');
        $tab = request()->query('tab');
        [$this->area, $this->initialTab] = PersonalWorkspaceAreas::resolve(is_string($area) ? $area : null, is_string($tab) ? $tab : null);
        if ($this->area === 'work') {
            $this->initialRequest = request()->only(['request_type', 'request_id', 'request_revision']);
        }
    }

    public function render()
    {
        OperationsAccess::requireReady();
        OperationsAccess::own(auth()->user(), auth()->id());
        $definition = PersonalWorkspaceAreas::get($this->area);
        abort_unless($definition, 503);

        return view('livewire.operations.personal-workspace', ['definition' => $definition])
            ->layout('layouts.master', ['contentMode' => $this->area === 'work' ? 'viewport' : null]);
    }
}
