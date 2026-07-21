<?php

namespace App\Livewire\Admin;

use App\Support\Operations\OperationalPreviewCatalog;
use Livewire\Component;

class OperationalPreview extends Component
{
    public string $module;

    public function mount(string $module): void
    {
        abort_unless(in_array($module, OperationalPreviewCatalog::slugs(), true), 404);

        $this->module = $module;
    }

    public function render(OperationalPreviewCatalog $catalog)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $module = $catalog->find($this->module);
        abort_unless($module, 404);

        return view('livewire.admin.operational-preview', [
            'moduleData' => $module,
            'allModules' => $catalog->dashboard(),
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
