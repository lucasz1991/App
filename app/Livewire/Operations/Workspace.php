<?php

namespace App\Livewire\Operations;

use App\Support\Operations\OperationsPages;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Workspace extends Component
{
    #[Locked]
    public string $module;

    #[Locked]
    public string $initialTab = '';

    public function mount(string $module): void
    {
        $this->module = $module;
        $tab = request()->query('tab', '');
        $this->initialTab = is_string($tab) ? mb_substr($tab, 0, 40) : '';
        $this->authorizeModule();
        $this->redirect(OperationsPages::legacyUrlFor(auth()->user(), $module, request()->query()), navigate: true);
    }

    private function authorizeModule(): void
    {
        OperationsPages::authorizeLegacy(auth()->user(), $this->module);
    }

    public function render()
    {
        $this->authorizeModule();

        // Legacy URLs authorize before redirecting; never mount the obsolete child tree.
        return '<div></div>';
    }
}
