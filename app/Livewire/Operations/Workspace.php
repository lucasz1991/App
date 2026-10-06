<?php

namespace App\Livewire\Operations;

use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\WorkforceAccountService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\WorkforcePlanningSchema;
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
        $target = OperationsPages::legacyTarget($module, request()->query());
        $this->redirectRoute('operations.page', $target, navigate: true);
    }

    private function authorizeModule(): void
    {
        $definition = OperationsNavigation::modules()[$this->module] ?? null;
        abort_unless($definition, 404);
        OperationsAccess::authorize(auth()->user(), $definition['ability']);
        OperationsAccess::requireReady();
        abort_unless(OperationsNavigation::enhancementReady($this->module), 503, 'Arbeitsbereich nicht verfügbar.');
        if (in_array($this->module, ['workforce-planning', 'plan-variants'], true)) {
            abort_unless(WorkforcePlanningSchema::ready(), 503, 'Die Planungsmodule sind noch nicht eingerichtet.');
        }
        if ($this->module === 'workforce-accounts') {
            abort_unless(app(WorkforceAccountService::class)->ready(), 503, 'Die Personalkonten sind noch nicht eingerichtet.');
        }
        if ($this->module === 'personnel-processes') {
            abort_unless(app(PersonnelProcessService::class)->ready(), 503, 'Die Personalprozesse sind noch nicht eingerichtet.');
        }
    }

    public function render()
    {
        $this->authorizeModule();

        return view('livewire.operations.workspace', ['modules' => OperationsNavigation::forUser(auth()->user())])->layout('layouts.master');
    }
}
