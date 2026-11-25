<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresAdmin;
use App\Support\Operations\OperationalPreviewCatalog;
use App\Support\Operations\OperationsAccess;
use Livewire\Component;

class OperationalPreview extends Component
{
    use RequiresAdmin;

    public string $module;

    public function mount(string $module): void
    {
        $this->adminActor();
        abort_unless(in_array($module, OperationalPreviewCatalog::slugs(), true), 404);

        $this->module = $module;
        if (OperationsAccess::ready()) {
            $this->redirectRoute('operations.workspace', ['module' => $module]);
        }
    }

    public function render(OperationalPreviewCatalog $catalog)
    {
        $this->adminActor();
        // Module navigation needs labels and icons only. Dashboard metrics are
        // intentionally not queried on every operational workspace render.
        $allModules = $catalog->definitions();
        $moduleData = $allModules[$this->module] ?? null;
        abort_unless($moduleData, 404);

        return view('livewire.admin.operational-preview', [
            'moduleData' => $moduleData,
            'allModules' => $allModules,
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
