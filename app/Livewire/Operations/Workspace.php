<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Workspace extends Component
{
    #[Locked]
    public string $module;

    public function mount(string $module): void
    {
        $this->module = $module;
        $this->authorizeModule();
    }

    private function authorizeModule(): void
    {
        $definition = OperationsNavigation::modules()[$this->module] ?? null;
        abort_unless($definition, 404);
        OperationsAccess::authorize(auth()->user(), $definition['ability']);
        OperationsAccess::requireReady();
    }

    public function render()
    {
        $this->authorizeModule();

        return view('livewire.operations.workspace', ['modules' => OperationsNavigation::forUser(auth()->user())])->layout('layouts.master');
    }
}
